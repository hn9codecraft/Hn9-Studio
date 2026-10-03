<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Story\Contracts\LiveStoryVideoProviderAdapterInterface;
use App\Story\Enums\StoryVideoAsyncMode;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Video\StoryVideoGenerationOutput;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoModelSpec;
use App\Story\Video\StoryVideoSubmission;
use Illuminate\Support\Facades\Storage;

/**
 * One fake provider with three completion styles. The generation service must not branch on which.
 */
final class ContractStoryVideoAdapter implements LiveStoryVideoProviderAdapterInterface
{
    public int $submits = 0;

    /** @var list<int> */
    public array $submittedDurations = [];

    public bool $failSubmit = false;

    public int $transientFailures = 0;

    public bool $permanentFailure = false;

    /** ok, missing, wrong_duration, bad_mime, empty, download_failed */
    public string $output = 'ok';

    /** poll, callback, or sync */
    public string $completion = 'poll';

    public int $pollsUntilReady = 1;

    public bool $callbackReceived = false;

    public int $statusCalls = 0;

    public bool $available = true;

    /** @var list<StoryVideoCapability>|null */
    public ?array $capabilities = null;

    public bool $rejectBeforeAccept = false;

    public string $vendorName = 'contract';

    /** @param  list<int>  $durations */
    public function __construct(
        public array $durations = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
        public string $adapterKey = 'video.contract',
    ) {}

    public function key(): string
    {
        return $this->adapterKey;
    }

    public function vendor(): string
    {
        return $this->vendorName;
    }

    public function displayName(): string
    {
        return 'Contract video';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function priority(): int
    {
        return 900;
    }

    public function supports(StoryVideoCapability $capability): bool
    {
        $modes = $this->capabilities ?? [
            StoryVideoCapability::TextToVideo,
            StoryVideoCapability::ImageToVideo,
            StoryVideoCapability::ReferenceToVideo,
        ];

        return in_array($capability, $modes, true);
    }

    public function isAvailable(StoryVideoCapability $capability): bool
    {
        return $this->available && $this->supports($capability);
    }

    public function supportedDurations(StoryVideoCapability $capability): array
    {
        return $this->durations;
    }

    public function minDurationSeconds(StoryVideoCapability $capability): ?int
    {
        return $this->durations === [] ? null : min($this->durations);
    }

    public function maxDurationSeconds(StoryVideoCapability $capability): ?int
    {
        return $this->durations === [] ? null : max($this->durations);
    }

    public function supportedAspectRatios(StoryVideoCapability $capability): array
    {
        return ['16:9', '9:16', '1:1'];
    }

    public function supportedResolutions(StoryVideoCapability $capability): array
    {
        return ['720p'];
    }

    public function supportedInputTypes(StoryVideoCapability $capability): array
    {
        return ['text', 'image', 'reference_image'];
    }

    public function audioSupported(StoryVideoCapability $capability): bool
    {
        return false;
    }

    public function supportedAudioRoles(StoryVideoCapability $capability): array
    {
        return [];
    }

    public function asyncMode(StoryVideoCapability $capability): StoryVideoAsyncMode
    {
        return match ($this->completion) {
            'sync' => StoryVideoAsyncMode::Sync,
            'callback' => StoryVideoAsyncMode::AsyncWebhook,
            default => StoryVideoAsyncMode::AsyncPoll,
        };
    }

    public function supportsPolling(StoryVideoCapability $capability): bool
    {
        return true;
    }

    public function supportsWebhook(StoryVideoCapability $capability): bool
    {
        return $this->completion === 'callback';
    }

    public function supportsDownload(StoryVideoCapability $capability): bool
    {
        return true;
    }

    public function models(): array
    {
        return [
            new StoryVideoModelSpec(
                providerKey: $this->adapterKey,
                modelKey: 'contract-model',
                displayName: 'Contract model',
                capabilities: $this->capabilities ?? [StoryVideoCapability::TextToVideo, StoryVideoCapability::ImageToVideo, StoryVideoCapability::ReferenceToVideo],
                enabled: true,
                priority: 900,
                durations: $this->durations,
                aspectRatios: ['16:9', '9:16', '1:1'],
                resolutions: ['720p'],
                inputTypes: ['text', 'image', 'reference_image'],
            ),
        ];
    }

    public function validate(StoryVideoGenerationRequest $request): void
    {
        if ($this->rejectBeforeAccept) {
            throw StoryVideoEngineException::invalidInput('The provider rejected the request before accepting it.');
        }
    }

    public function submit(StoryVideoGenerationRequest $request): StoryVideoSubmission
    {
        $this->submits++;
        $this->submittedDurations[] = (int) $request->durationSeconds;
        if ($this->failSubmit) {
            throw StoryVideoEngineException::provider(StoryVideoErrorCode::UpstreamError, 'provider key=secret submit failed');
        }

        return new StoryVideoSubmission('contract-op-'.$this->submits, StoryVideoJobStatus::Submitted, 'contract-model');
    }

    public function status(StoryVideoGenerationJob $job): StoryVideoJobStatus
    {
        if ($this->permanentFailure) {
            throw StoryVideoEngineException::invalidInput('The provider rejected the request.');
        }
        if ($this->transientFailures > 0) {
            $this->transientFailures--;
            throw StoryVideoEngineException::provider(StoryVideoErrorCode::RateLimited, 'try again');
        }
        $this->statusCalls++;
        if (! $this->ready()) {
            $job->forceFill(['status' => StoryVideoJobStatus::Processing->value])->save();

            return StoryVideoJobStatus::Processing;
        }
        if ($this->output === 'download_failed') {
            $job->forceFill([
                'status' => StoryVideoJobStatus::Failed->value,
                'error_code' => StoryVideoErrorCode::DownloadFailed->value,
                'error_message' => 'The finished video could not be saved.',
                'failed_at' => now(),
            ])->save();

            return StoryVideoJobStatus::Failed;
        }
        if ($this->output === 'missing') {
            $job->forceFill([
                'status' => StoryVideoJobStatus::Completed->value,
                'completed_at' => now(),
            ])->save();

            return StoryVideoJobStatus::Completed;
        }

        $this->store($job);

        return StoryVideoJobStatus::Completed;
    }

    public function cancel(StoryVideoGenerationJob $job): void
    {
        $job->forceFill(['status' => StoryVideoJobStatus::Cancelled->value])->save();
    }

    public function result(StoryVideoGenerationJob $job): StoryVideoGenerationOutput
    {
        return new StoryVideoGenerationOutput(mediaReference: $job->provider_metadata['storage']['path'] ?? null, mimeType: 'video/mp4');
    }

    private function ready(): bool
    {
        return match ($this->completion) {
            'sync' => true,
            'callback' => $this->callbackReceived,
            default => $this->statusCalls > $this->pollsUntilReady,
        };
    }

    private function store(StoryVideoGenerationJob $job): void
    {
        $path = 'units/'.$job->uuid.'.mp4';
        $bytes = $this->output === 'empty' ? '' : 'fake-video';
        Storage::disk('videos')->put($path, $bytes);
        $requested = (float) ($job->request_payload['duration_seconds'] ?? 0);
        $job->forceFill([
            'status' => StoryVideoJobStatus::Completed->value,
            'completed_at' => now(),
            'error_code' => null,
            'error_message' => null,
            'provider_metadata' => array_merge((array) $job->provider_metadata, [
                'storage' => [
                    'disk' => 'videos',
                    'path' => $path,
                    'mime' => $this->output === 'bad_mime' ? 'text/plain' : 'video/mp4',
                    'size' => strlen($bytes),
                    'duration_seconds' => $this->output === 'wrong_duration' ? 30 : $requested,
                ],
            ]),
        ])->save();
    }
}
