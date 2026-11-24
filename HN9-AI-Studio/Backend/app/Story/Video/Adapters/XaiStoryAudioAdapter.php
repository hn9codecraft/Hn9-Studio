<?php

declare(strict_types=1);

namespace App\Story\Video\Adapters;

use App\AI\Contracts\ProviderDispatcherInterface;
use App\AI\Exceptions\AIException;
use App\AI\Execution\DispatchOptions;
use App\AI\Providers\Xai\XaiConfig;
use App\AI\Requests\VoiceRequest;
use App\Story\Contracts\LiveStoryVideoProviderAdapterInterface;
use App\Story\Contracts\StoryAudioVoiceCatalogInterface;
use App\Story\Enums\StoryVideoAsyncMode;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Support\StoryVoiceProviderSelector;
use App\Story\Video\CatalogStoryVideoAdapter;
use App\Story\Video\StoryVideoGenerationOutput;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoSubmission;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * English and Hindi speech through the xAI text-to-speech endpoint.
 * Gujarati is refused here unless the experimental flag is on.
 */
final readonly class XaiStoryAudioAdapter implements LiveStoryVideoProviderAdapterInterface, StoryAudioVoiceCatalogInterface
{
    public const VENDOR = 'xai';

    private const DISK = 'voice';

    private const PENDING_DIR = 'pending';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private CatalogStoryVideoAdapter $catalog,
        private ProviderDispatcherInterface $dispatcher,
        private array $config,
    ) {}

    public function vendor(): string
    {
        return self::VENDOR;
    }

    public function key(): string
    {
        return $this->catalog->key();
    }

    public function displayName(): string
    {
        return $this->catalog->displayName();
    }

    public function enabled(): bool
    {
        return $this->catalog->enabled();
    }

    public function priority(): int
    {
        return $this->catalog->priority();
    }

    public function supports(StoryVideoCapability $capability): bool
    {
        return $this->catalog->supports($capability);
    }

    public function isAvailable(StoryVideoCapability $capability): bool
    {
        return $this->catalog->isAvailable($capability);
    }

    public function supportedDurations(StoryVideoCapability $capability): array
    {
        return $this->catalog->supportedDurations($capability);
    }

    public function minDurationSeconds(StoryVideoCapability $capability): ?int
    {
        return $this->catalog->minDurationSeconds($capability);
    }

    public function maxDurationSeconds(StoryVideoCapability $capability): ?int
    {
        return $this->catalog->maxDurationSeconds($capability);
    }

    public function supportedAspectRatios(StoryVideoCapability $capability): array
    {
        return $this->catalog->supportedAspectRatios($capability);
    }

    public function supportedResolutions(StoryVideoCapability $capability): array
    {
        return $this->catalog->supportedResolutions($capability);
    }

    public function supportedInputTypes(StoryVideoCapability $capability): array
    {
        return $this->catalog->supportedInputTypes($capability);
    }

    public function audioSupported(StoryVideoCapability $capability): bool
    {
        return $this->catalog->audioSupported($capability);
    }

    public function supportedAudioRoles(StoryVideoCapability $capability): array
    {
        return $this->catalog->supportedAudioRoles($capability);
    }

    public function asyncMode(StoryVideoCapability $capability): StoryVideoAsyncMode
    {
        return $this->catalog->asyncMode($capability);
    }

    public function supportsPolling(StoryVideoCapability $capability): bool
    {
        return $this->catalog->supportsPolling($capability);
    }

    public function supportsWebhook(StoryVideoCapability $capability): bool
    {
        return false;
    }

    public function supportsDownload(StoryVideoCapability $capability): bool
    {
        return $this->catalog->supportsDownload($capability);
    }

    public function models(): array
    {
        return $this->catalog->models();
    }

    public function voiceNames(): array
    {
        return ['eve'];
    }

    public function defaultVoiceName(): ?string
    {
        return 'eve';
    }

    public function validate(StoryVideoGenerationRequest $request): void
    {
        $this->catalog->validate($request);
        $text = trim((string) $request->prompt);
        if ($text === '') {
            throw StoryVideoEngineException::invalidInput('Write the words that should be spoken.');
        }
        $language = strtolower((string) ($request->metadata['voice_language'] ?? $request->metadata['story_language_code'] ?? $request->metadata['language'] ?? ''));
        if ($language === 'gu' && ! app(StoryVoiceProviderSelector::class)->gujaratiExperimental()) {
            throw StoryVideoEngineException::invalidInput('Gujarati voice stays on ElevenLabs.');
        }
    }

    public function submit(StoryVideoGenerationRequest $request): StoryVideoSubmission
    {
        $this->validate($request);
        $language = $request->metadata['voice_language'] ?? $request->metadata['story_language_code'] ?? null;
        $voice = $request->metadata['story_voice_id'] ?? $request->metadata['voice'] ?? null;

        try {
            $response = $this->dispatcher->voice(
                new VoiceRequest(
                    input: trim((string) $request->prompt),
                    voice: is_string($voice) && $voice !== '' ? $voice : null,
                    language: is_string($language) && $language !== '' ? $language : null,
                    options: config('story_subtitles.timestamps_enabled') ? ['with_timestamps' => true] : ['with_timestamps' => false],
                ),
                DispatchOptions::only(XaiConfig::KEY),
            );
        } catch (Throwable $exception) {
            throw $this->failure($exception);
        }

        $audio = $this->decode($response->audio);
        if ($audio === null) {
            throw StoryVideoEngineException::provider(
                StoryVideoErrorCode::InvalidProviderResponse,
                'The sound service returned no audio. Try again.',
            );
        }

        $operationId = Str::uuid()->toString().'.'.$audio['extension'];
        if (! Storage::disk(self::DISK)->put(self::PENDING_DIR.'/'.$operationId, $audio['bytes'])) {
            throw StoryVideoEngineException::provider(
                StoryVideoErrorCode::DownloadFailed,
                'The sound was created but could not be saved. Try again.',
            );
        }
        $this->storeAlignment($operationId, $response->raw['alignment'] ?? null);

        return new StoryVideoSubmission(
            operationId: $operationId,
            status: StoryVideoJobStatus::Processing,
            modelKey: $response->model,
            done: true,
        );
    }

    public function status(StoryVideoGenerationJob $job): StoryVideoJobStatus
    {
        if ($this->storedOutput($job) !== null) {
            return StoryVideoJobStatus::Completed;
        }
        $pending = $this->pendingPath((string) $job->operation_id);
        if ($pending === null || ! Storage::disk(self::DISK)->exists($pending)) {
            return StoryVideoJobStatus::Failed;
        }

        return StoryVideoJobStatus::Processing;
    }

    public function cancel(StoryVideoGenerationJob $job): void
    {
        $job->forceFill(['status' => StoryVideoJobStatus::Cancelled->value])->save();
    }

    public function result(StoryVideoGenerationJob $job): StoryVideoGenerationOutput
    {
        $stored = $this->storedOutput($job);
        if ($stored !== null) {
            return $stored;
        }

        $pending = $this->pendingPath((string) $job->operation_id);
        $disk = Storage::disk(self::DISK);
        if ($pending === null || ! $disk->exists($pending)) {
            throw StoryVideoEngineException::provider(StoryVideoErrorCode::DownloadFailed, 'The sound could not be saved. Try again.');
        }

        $job->loadMissing('workspace.project');
        $project = $job->workspace?->project;
        if ($project === null) {
            throw StoryVideoEngineException::invalidInput('The story workspace has no project for private storage.');
        }

        $bytes = (string) $disk->get($pending);
        $path = $project->uuid.'/'.basename($pending);
        if ($bytes === '' || ! $disk->move($pending, $path)) {
            throw StoryVideoEngineException::provider(StoryVideoErrorCode::DownloadFailed, 'The sound could not be saved. Try again.');
        }

        $job->forceFill([
            'status' => StoryVideoJobStatus::Completed->value,
            'completed_at' => now(),
            'provider_metadata' => array_merge((array) $job->provider_metadata, [
                'storage' => ['disk' => self::DISK, 'path' => $path, 'mime' => 'audio/mpeg', 'checksum' => hash('sha256', $bytes)],
            ]),
        ])->save();

        return new StoryVideoGenerationOutput(
            mediaReference: $path,
            mimeType: 'audio/mpeg',
            providerOutputId: $job->operation_id,
            downloadStrategy: 'private_file',
            checksum: hash('sha256', $bytes),
            metadata: ['disk' => self::DISK],
        );
    }

    private function failure(Throwable $exception): StoryVideoEngineException
    {
        if ($exception instanceof StoryVideoEngineException) {
            return $exception;
        }
        $message = $exception instanceof AIException
            ? $exception->getMessage()
            : 'Sound generation failed. Try again.';

        return StoryVideoEngineException::provider(StoryVideoErrorCode::UpstreamError, $message);
    }

    /**
     * @return array{bytes: string, extension: string}|null
     */
    private function decode(string $dataUri): ?array
    {
        if (preg_match('#^data:(audio/[a-z0-9.+-]+)(?:;[^,]*)?;base64,(.+)$#is', $dataUri, $match) !== 1) {
            return null;
        }
        $bytes = base64_decode($match[2], true);
        if (! is_string($bytes) || $bytes === '') {
            return null;
        }
        $mime = strtolower($match[1]);
        $extension = match (true) {
            str_contains($mime, 'wav') => 'wav',
            str_contains($mime, 'ogg') => 'ogg',
            default => 'mp3',
        };

        return ['bytes' => $bytes, 'extension' => $extension];
    }

    private function pendingPath(string $operationId): ?string
    {
        return preg_match('/^[0-9a-f-]{36}\.(mp3|wav|ogg)$/', $operationId) === 1
            ? self::PENDING_DIR.'/'.$operationId
            : null;
    }

    private function storedOutput(StoryVideoGenerationJob $job): ?StoryVideoGenerationOutput
    {
        $stored = $job->provider_metadata['storage'] ?? null;
        if (! is_array($stored) || ! isset($stored['disk'], $stored['path'])) {
            return null;
        }

        return new StoryVideoGenerationOutput(
            mediaReference: (string) $stored['path'],
            mimeType: isset($stored['mime']) ? (string) $stored['mime'] : null,
            providerOutputId: $job->operation_id,
            downloadStrategy: 'private_file',
            checksum: isset($stored['checksum']) ? (string) $stored['checksum'] : null,
            metadata: ['disk' => (string) $stored['disk']],
        );
    }

    private function storeAlignment(string $operationId, mixed $alignment): void
    {
        if (! is_array($alignment)) {
            return;
        }
        $payload = [
            'characters' => array_values(array_map('strval', (array) ($alignment['characters'] ?? []))),
            'character_start_times_seconds' => array_values((array) ($alignment['character_start_times_seconds'] ?? [])),
            'character_end_times_seconds' => array_values((array) ($alignment['character_end_times_seconds'] ?? [])),
        ];
        Storage::disk(self::DISK)->put('alignments/'.$operationId.'.json', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
