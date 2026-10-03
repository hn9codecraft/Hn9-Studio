<?php

declare(strict_types=1);

namespace App\Story\Video\Adapters;

use App\AI\Support\ProviderErrorSanitizer;
use App\Models\Project;
use App\Services\VideoBinaryStore;
use App\Story\Contracts\LiveStoryVideoProviderAdapterInterface;
use App\Story\Enums\StoryVideoAsyncMode;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Enums\StoryVideoInputType;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Video\CatalogStoryVideoAdapter;
use App\Story\Video\StoryVideoGenerationOutput;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoSubmission;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Shared submit / poll / download flow for HTTP task-based video providers.
 * Capability metadata comes from a catalog description; vendor request and
 * response shapes live in the concrete adapter only.
 *
 * Provider output URLs are short-lived and are never persisted: a finished
 * task is fetched again at download time and only the private file is kept.
 */
abstract class HttpStoryVideoAdapter implements LiveStoryVideoProviderAdapterInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly CatalogStoryVideoAdapter $catalog,
        protected readonly VideoBinaryStore $binaries,
        protected readonly array $config,
    ) {}

    /**
     * @return array{id: string, model?: string|null}
     */
    abstract protected function createTask(StoryVideoGenerationRequest $request): array;

    /**
     * @return array<string, mixed>
     */
    abstract protected function fetchTask(string $operationId): array;

    /**
     * @param  array<string, mixed>  $task
     */
    abstract protected function interpretTask(array $task): StoryVideoTaskState;

    abstract protected function cancelTask(string $operationId): void;

    /**
     * Vendor-specific input rules, checked before any network call.
     */
    abstract protected function validateProviderRules(StoryVideoGenerationRequest $request): void;

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
        return $this->catalog->supportsWebhook($capability);
    }

    public function supportsDownload(StoryVideoCapability $capability): bool
    {
        return $this->catalog->supportsDownload($capability);
    }

    public function models(): array
    {
        return $this->catalog->models();
    }

    public function validate(StoryVideoGenerationRequest $request): void
    {
        $this->catalog->validate($request);
        if (trim((string) $request->prompt) === '' && $request->capability !== StoryVideoCapability::ImageToVideo) {
            throw StoryVideoEngineException::invalidInput('Describe what should happen in this video.');
        }
        if (in_array($request->capability, [StoryVideoCapability::ImageToVideo, StoryVideoCapability::ReferenceToVideo], true)
            && $this->storedMedia($request, [StoryVideoInputType::Image, StoryVideoInputType::ReferenceImage], 'images') === []) {
            throw StoryVideoEngineException::invalidInput('Choose a character or style picture for this video.');
        }
        if (in_array($request->capability, [StoryVideoCapability::VideoEdit, StoryVideoCapability::VideoExtend], true)
            && $this->storedMedia($request, [StoryVideoInputType::Video], 'videos') === []) {
            throw StoryVideoEngineException::invalidInput('A stored scene video is required.');
        }
        $this->validateProviderRules($request);
    }

    public function submit(StoryVideoGenerationRequest $request): StoryVideoSubmission
    {
        $this->validate($request);
        $task = $this->createTask($request);
        $id = $task['id'];
        if ($id === '') {
            throw StoryVideoEngineException::provider(
                StoryVideoErrorCode::InvalidProviderResponse,
                'The video service did not confirm the request.',
            );
        }

        return new StoryVideoSubmission(
            operationId: $id,
            status: StoryVideoJobStatus::Submitted,
            modelKey: $task['model'] ?? $request->preferredModel ?? $this->defaultModel(),
        );
    }

    public function status(StoryVideoGenerationJob $job): StoryVideoJobStatus
    {
        if ($this->storedOutput($job) !== null) {
            return StoryVideoJobStatus::Completed;
        }

        $operation = (string) $job->operation_id;
        if ($operation === '') {
            return StoryVideoJobStatus::tryFrom((string) $job->status) ?? StoryVideoJobStatus::Queued;
        }

        try {
            $task = $this->interpretTask($this->fetchTask($operation));
        } catch (StoryVideoEngineException $exception) {
            // The verdict is unknown; the runner decides whether to poll again.
            $job->forceFill([
                'error_code' => $exception->errorCode(),
                'error_message' => $exception->getMessage(),
            ])->save();

            return StoryVideoJobStatus::Failed;
        }

        $metadata = (array) $job->provider_metadata;
        if ($task->usage !== []) {
            $metadata['usage'] = $task->usage;
        }
        if ($task->progress !== null) {
            $metadata['progress'] = round($task->progress, 2);
        }

        if ($task->state === StoryVideoTaskState::FAILED) {
            $job->forceFill([
                'status' => StoryVideoJobStatus::Failed->value,
                'error_code' => ($task->errorCode ?? StoryVideoErrorCode::UpstreamError)->value,
                'error_message' => $this->safeText($task->errorMessage ?? 'The video service could not finish this video.'),
                'failed_at' => now(),
                'provider_metadata' => $metadata,
            ])->save();

            return StoryVideoJobStatus::Failed;
        }

        if ($task->state === StoryVideoTaskState::SUCCEEDED) {
            if ($task->videoUrl === null || $task->videoUrl === '') {
                $job->forceFill([
                    'status' => StoryVideoJobStatus::Failed->value,
                    'error_code' => StoryVideoErrorCode::InvalidProviderResponse->value,
                    'error_message' => 'The video service finished but returned no video.',
                    'failed_at' => now(),
                    'provider_metadata' => $metadata,
                ])->save();

                return StoryVideoJobStatus::Failed;
            }
            $metadata['download_pending'] = true;
        }

        $job->forceFill([
            'status' => StoryVideoJobStatus::Processing->value,
            'provider_metadata' => $metadata,
        ])->save();

        return StoryVideoJobStatus::Processing;
    }

    public function cancel(StoryVideoGenerationJob $job): void
    {
        $operation = (string) $job->operation_id;
        if ($operation !== '') {
            try {
                $this->cancelTask($operation);
            } catch (Throwable) {
                // The local record is cancelled either way; the provider may already be done.
            }
        }

        $job->forceFill(['status' => StoryVideoJobStatus::Cancelled->value])->save();
    }

    public function result(StoryVideoGenerationJob $job): StoryVideoGenerationOutput
    {
        $stored = $this->storedOutput($job);
        if ($stored !== null) {
            return $stored;
        }

        $task = $this->interpretTask($this->fetchTask((string) $job->operation_id));
        if ($task->state !== StoryVideoTaskState::SUCCEEDED || $task->videoUrl === null || $task->videoUrl === '') {
            throw StoryVideoEngineException::invalidInput('The video is not ready to download yet.');
        }

        $project = $this->project($job);
        $metadata = (array) $job->provider_metadata;

        try {
            $file = $this->binaries->retrieveUrlAndStore(
                $project,
                $task->videoUrl,
                (int) ($this->config['download_timeout_seconds'] ?? 180),
            );
        } catch (Throwable $exception) {
            $job->forceFill([
                'status' => StoryVideoJobStatus::Failed->value,
                'error_code' => StoryVideoErrorCode::DownloadFailed->value,
                'error_message' => 'The finished video could not be saved. Try creating it again.',
                'failed_at' => now(),
                'provider_metadata' => array_merge($metadata, ['download_pending' => false]),
            ])->save();

            throw StoryVideoEngineException::provider(
                StoryVideoErrorCode::DownloadFailed,
                'The finished video could not be saved. Try creating it again.',
            );
        }

        $job->forceFill([
            'status' => StoryVideoJobStatus::Completed->value,
            'completed_at' => now(),
            'error_code' => null,
            'error_message' => null,
            'provider_metadata' => array_merge($metadata, [
                'download_pending' => false,
                'storage' => [
                    'disk' => $file->disk,
                    'path' => $file->path,
                    'mime' => $file->mimeType,
                    'size' => $file->size,
                    'checksum' => $file->checksum,
                ],
            ]),
        ])->save();

        return new StoryVideoGenerationOutput(
            mediaReference: $file->path,
            mimeType: $file->mimeType,
            providerOutputId: $job->operation_id,
            downloadStrategy: 'private_file',
            checksum: $file->checksum,
            metadata: ['disk' => $file->disk],
        );
    }

    protected function defaultModel(): ?string
    {
        $model = $this->config['model'] ?? null;

        return is_string($model) && $model !== '' ? $model : null;
    }

    protected function modelFor(StoryVideoGenerationRequest $request): string
    {
        $preferred = $request->preferredModel;
        foreach ($this->models() as $model) {
            if ($preferred !== null && $model->modelKey === $preferred && $model->enabled) {
                return $model->modelKey;
            }
        }

        return (string) $this->defaultModel();
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [];
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) ($this->config['base_url'] ?? ''), '/'))
            ->withToken((string) ($this->config['api_key'] ?? ''))
            ->withHeaders($this->headers())
            ->acceptJson()
            ->asJson()
            ->connectTimeout(max(1, (int) ($this->config['connect_timeout_seconds'] ?? 10)))
            ->timeout(max(5, (int) ($this->config['request_timeout_seconds'] ?? 60)));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function send(string $method, string $path, array $body = []): array
    {
        try {
            $client = $this->client();
            $response = match (strtoupper($method)) {
                'POST' => $client->post($path, $body),
                'DELETE' => $client->delete($path),
                default => $client->get($path),
            };
        } catch (ConnectionException $exception) {
            $timedOut = str_contains(strtolower($exception->getMessage()), 'timed out')
                || str_contains(strtolower($exception->getMessage()), 'timeout');

            throw StoryVideoEngineException::provider(
                $timedOut ? StoryVideoErrorCode::Timeout : StoryVideoErrorCode::ProviderUnavailable,
                $timedOut ? 'The video service took too long to answer.' : 'The video service could not be reached.',
            );
        }

        if (! $response->successful()) {
            throw $this->httpError($response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    protected function httpError(Response $response): StoryVideoEngineException
    {
        $status = $response->status();
        $code = match (true) {
            in_array($status, [401, 403], true) => StoryVideoErrorCode::AuthenticationFailed,
            $status === 402 => StoryVideoErrorCode::QuotaExceeded,
            $status === 429 => StoryVideoErrorCode::RateLimited,
            in_array($status, [408, 504], true) => StoryVideoErrorCode::Timeout,
            in_array($status, [502, 503], true) => StoryVideoErrorCode::ProviderUnavailable,
            $status >= 500 => StoryVideoErrorCode::UpstreamError,
            default => StoryVideoErrorCode::InvalidInput,
        };

        $friendly = match ($code) {
            StoryVideoErrorCode::AuthenticationFailed => 'The video service rejected the saved credentials.',
            StoryVideoErrorCode::QuotaExceeded => 'The video service account has no remaining credit.',
            StoryVideoErrorCode::RateLimited => 'The video service is busy. It will be tried again shortly.',
            StoryVideoErrorCode::Timeout => 'The video service took too long to answer.',
            StoryVideoErrorCode::ProviderUnavailable, StoryVideoErrorCode::UpstreamError => 'The video service is temporarily unavailable.',
            default => 'The video service could not accept this request.',
        };

        $detail = $this->providerErrorText($response);
        if ($code === StoryVideoErrorCode::InvalidInput && $detail !== null) {
            $friendly .= ' '.$detail;
        }

        return StoryVideoEngineException::provider($code, $this->safeText($friendly));
    }

    protected function providerErrorText(Response $response): ?string
    {
        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        $candidates = [
            $json['error']['message'] ?? null,
            is_string($json['error'] ?? null) ? $json['error'] : null,
            $json['detail'] ?? null,
            $json['message'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return mb_substr(trim($candidate), 0, 200);
            }
        }

        return null;
    }

    /**
     * Owned media referenced by the request, read from private storage.
     *
     * @param  list<StoryVideoInputType>  $types
     * @return list<array{bytes: string, mime: string, role: string|null, type: StoryVideoInputType}>
     */
    protected function storedMedia(StoryVideoGenerationRequest $request, array $types, string $disk): array
    {
        $found = [];
        foreach ($request->inputs as $input) {
            if (! in_array($input->type, $types, true)) {
                continue;
            }
            $inputDisk = $input->metadata['disk'] ?? null;
            $path = $input->metadata['path'] ?? null;
            if ($inputDisk !== $disk || ! is_string($path) || $path === '' || str_contains($path, '..') || str_contains($path, '://')) {
                continue;
            }
            if (! Storage::disk($disk)->exists($path)) {
                continue;
            }
            $bytes = Storage::disk($disk)->get($path);
            if (! is_string($bytes) || $bytes === '') {
                continue;
            }
            $mime = $input->metadata['mime'] ?? null;
            $found[] = [
                'bytes' => $bytes,
                'mime' => is_string($mime) && $mime !== '' ? $mime : ($disk === 'videos' ? 'video/mp4' : 'image/png'),
                'role' => $input->role,
                'type' => $input->type,
            ];
        }

        return $found;
    }

    protected function dataUri(string $bytes, string $mime): string
    {
        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    protected function safeText(string $message): string
    {
        $message = (string) preg_replace('#https?://\S+#i', '[link removed]', $message);
        $message = (string) preg_replace('/(?:^|[?&\s])(?:key|api_key|token|signature|x-amz-[a-z-]+)=\S+/i', ' [redacted]', $message);
        $message = (string) preg_replace('/\b(?:sk|ark|key|luma|rw)[-_][A-Za-z0-9_\-]{12,}\b/', '[redacted]', $message);

        return mb_substr(ProviderErrorSanitizer::message(trim($message), 'The video service request failed.'), 0, 300);
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

    private function project(StoryVideoGenerationJob $job): Project
    {
        $job->loadMissing('workspace.project');
        $project = $job->workspace?->project;
        if (! $project instanceof Project) {
            throw StoryVideoEngineException::invalidInput('The story workspace has no project for private storage.');
        }

        return $project;
    }
}
