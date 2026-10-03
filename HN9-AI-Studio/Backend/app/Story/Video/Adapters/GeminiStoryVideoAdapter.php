<?php

declare(strict_types=1);

namespace App\Story\Video\Adapters;

use App\AI\Exceptions\AIException;
use App\AI\Exceptions\ProviderAuthenticationException;
use App\AI\Exceptions\ProviderNetworkException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Providers\Gemini\GeminiProvider;
use App\AI\Support\ProviderErrorSanitizer;
use App\AI\Requests\VideoRequest;
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
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Real video adapter. Vendor client usage stays in this class.
 * Core services see only the adapter key and normalized jobs.
 */
final readonly class GeminiStoryVideoAdapter implements LiveStoryVideoProviderAdapterInterface
{
    public function __construct(
        private CatalogStoryVideoAdapter $catalog,
        private GeminiProvider $provider,
        private VideoBinaryStore $binaries,
    ) {}

    public function vendor(): string
    {
        return 'gemini';
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
        if (in_array($request->capability, [StoryVideoCapability::ImageToVideo, StoryVideoCapability::ReferenceToVideo], true)) {
            $image = $this->imagePayload($request);
            if ($image === null) {
                throw StoryVideoEngineException::invalidInput('An owned image reference is required for this capability.');
            }
        }
        if (in_array($request->capability, [StoryVideoCapability::VideoEdit, StoryVideoCapability::VideoExtend], true)
            && $this->videoPayload($request) === null) {
            throw StoryVideoEngineException::invalidInput('A stored scene video is required for this capability.');
        }
        if ($request->capability === StoryVideoCapability::Audio) {
            $prompt = trim((string) ($request->prompt ?? ''));
            if ($prompt === '') {
                throw StoryVideoEngineException::invalidInput('An audio prompt is required.');
            }
        }
    }

    public function submit(StoryVideoGenerationRequest $request): StoryVideoSubmission
    {
        $this->validate($request);

        if ($request->capability === StoryVideoCapability::Audio) {
            return $this->submitAudio($request);
        }

        try {
            $response = $this->provider->generateVideo($this->toVideoRequest($request, null));
        } catch (Throwable $exception) {
            throw StoryVideoEngineException::invalidInput($this->safeMessage($exception));
        }

        $operationId = $response->jobId;
        if (! is_string($operationId) || $operationId === '') {
            throw StoryVideoEngineException::invalidInput('The video provider did not return an operation id.');
        }

        return new StoryVideoSubmission(
            operationId: $operationId,
            status: $response->done
                ? ($response->error ? StoryVideoJobStatus::Failed : StoryVideoJobStatus::Completed)
                : StoryVideoJobStatus::Submitted,
            modelKey: $response->model,
            done: $response->done,
            downloadUri: $this->safeUri($response->video),
        );
    }

    public function status(StoryVideoGenerationJob $job): StoryVideoJobStatus
    {
        $stored = $job->provider_metadata['storage'] ?? null;
        if (is_array($stored) && isset($stored['disk'], $stored['path'])) {
            return StoryVideoJobStatus::Completed;
        }

        $operation = (string) $job->operation_id;
        if ($operation === '') {
            return StoryVideoJobStatus::tryFrom((string) $job->status) ?? StoryVideoJobStatus::Queued;
        }

        $request = StoryVideoGenerationRequest::fromArray((array) $job->request_payload);
        if ($request->capability === StoryVideoCapability::Audio) {
            return $this->statusAudio($job, $request, $operation);
        }

        try {
            $response = $this->provider->generateVideo($this->toVideoRequest($request, $operation));
        } catch (Throwable $exception) {
            $job->forceFill([
                'error_code' => $this->pollErrorCode($exception)->value,
                'error_message' => $this->safeMessage($exception),
            ])->save();

            return StoryVideoJobStatus::Failed;
        }

        if (is_string($response->error) && $response->error !== '') {
            $job->forceFill([
                'status' => StoryVideoJobStatus::Failed->value,
                'error_code' => 'upstream_error',
                'error_message' => $this->safeMessageText($response->error),
                'failed_at' => now(),
            ])->save();

            return StoryVideoJobStatus::Failed;
        }

        if ($response->done) {
            if ($this->safeUri($response->video) === null) {
                $job->forceFill([
                    'status' => StoryVideoJobStatus::Failed->value,
                    'error_code' => 'upstream_error',
                    'error_message' => 'The provider video could not be stored.',
                    'failed_at' => now(),
                ])->save();

                return StoryVideoJobStatus::Failed;
            }

            $job->forceFill([
                'provider_metadata' => array_merge((array) $job->provider_metadata, [
                    'download_pending' => true,
                ]),
            ])->save();

            return StoryVideoJobStatus::Processing;
        }

        $status = StoryVideoJobStatus::Processing;
        $job->forceFill(['status' => $status->value])->save();

        return $status;
    }

    public function cancel(StoryVideoGenerationJob $job): void
    {
        $job->forceFill([
            'status' => StoryVideoJobStatus::Cancelled->value,
        ])->save();
    }

    public function result(StoryVideoGenerationJob $job): StoryVideoGenerationOutput
    {
        $metadata = (array) $job->provider_metadata;
        $stored = $metadata['storage'] ?? null;
        if (is_array($stored) && isset($stored['path'], $stored['disk'])) {
            return new StoryVideoGenerationOutput(
                mediaReference: (string) $stored['path'],
                mimeType: isset($stored['mime']) ? (string) $stored['mime'] : null,
                providerOutputId: $job->operation_id,
                downloadStrategy: 'private_file',
                checksum: isset($stored['checksum']) ? (string) $stored['checksum'] : null,
                metadata: ['disk' => (string) $stored['disk']],
            );
        }

        $request = StoryVideoGenerationRequest::fromArray((array) $job->request_payload);
        if ($request->capability === StoryVideoCapability::Audio) {
            return $this->resultAudio($job, $request);
        }

        $operation = (string) $job->operation_id;
        $response = $this->provider->generateVideo($this->toVideoRequest($request, $operation));
        $uri = $this->safeUri($response->video);
        if ($uri === null) {
            throw StoryVideoEngineException::invalidInput('The provider has not produced a downloadable video.');
        }

        $project = $job->workspace?->project;
        if (! $project instanceof Project) {
            $job->loadMissing('workspace.project');
            $project = $job->workspace?->project;
        }
        if (! $project instanceof Project) {
            throw StoryVideoEngineException::invalidInput('The story workspace has no project for private storage.');
        }

        try {
            $file = $this->binaries->retrieveAndStore($project, 'gemini', $uri);
        } catch (Throwable $exception) {
            $job->forceFill([
                'status' => StoryVideoJobStatus::Failed->value,
                'error_code' => 'upstream_error',
                'error_message' => $this->safeMessage($exception),
                'failed_at' => now(),
                'provider_metadata' => array_merge($metadata, [
                    'download_pending' => false,
                ]),
            ])->save();
            throw StoryVideoEngineException::invalidInput($this->safeMessage($exception));
        }

        $job->forceFill([
            'status' => StoryVideoJobStatus::Completed->value,
            'completed_at' => now(),
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

    private function submitAudio(StoryVideoGenerationRequest $request): StoryVideoSubmission
    {
        $payload = $this->audioHttp($request, null);
        $operationId = $payload['name'] ?? null;
        if (! is_string($operationId) || $operationId === '') {
            throw StoryVideoEngineException::invalidInput('The audio provider did not return an operation id.');
        }

        return new StoryVideoSubmission(
            operationId: $operationId,
            status: StoryVideoJobStatus::Submitted,
            modelKey: $request->preferredModel,
            done: false,
        );
    }

    private function statusAudio(
        StoryVideoGenerationJob $job,
        StoryVideoGenerationRequest $request,
        string $operation,
    ): StoryVideoJobStatus {
        try {
            $payload = $this->audioHttp($request, $operation);
        } catch (StoryVideoEngineException $exception) {
            $job->forceFill([
                'status' => StoryVideoJobStatus::Failed->value,
                'error_code' => $exception->errorCode(),
                'error_message' => $exception->getMessage(),
                'failed_at' => now(),
            ])->save();

            return StoryVideoJobStatus::Failed;
        }

        $done = (bool) ($payload['done'] ?? false);
        $inline = $this->audioInline($payload);
        if ($done && $inline === null) {
            $job->forceFill([
                'status' => StoryVideoJobStatus::Failed->value,
                'error_code' => 'upstream_error',
                'error_message' => 'The provider audio could not be stored.',
                'failed_at' => now(),
            ])->save();

            return StoryVideoJobStatus::Failed;
        }

        if ($done && $inline !== null) {
            $job->forceFill([
                'provider_metadata' => array_merge((array) $job->provider_metadata, [
                    'download_pending' => true,
                    'audio_inline' => [
                        'mime' => $inline['mime'],
                        'data' => $inline['data'],
                    ],
                ]),
            ])->save();

            return StoryVideoJobStatus::Processing;
        }

        $status = StoryVideoJobStatus::Processing;
        $job->forceFill(['status' => $status->value])->save();

        return $status;
    }

    private function resultAudio(
        StoryVideoGenerationJob $job,
        StoryVideoGenerationRequest $request,
    ): StoryVideoGenerationOutput {
        $metadata = (array) $job->provider_metadata;
        $inline = is_array($metadata['audio_inline'] ?? null) ? $metadata['audio_inline'] : null;
        if ($inline === null) {
            $payload = $this->audioHttp($request, (string) $job->operation_id);
            $inline = $this->audioInline($payload);
        }
        if ($inline === null) {
            throw StoryVideoEngineException::invalidInput('The provider has not produced downloadable audio.');
        }

        $bytes = base64_decode((string) $inline['data'], true);
        if (! is_string($bytes) || $bytes === '') {
            throw StoryVideoEngineException::invalidInput('The provider audio payload was empty.');
        }

        $project = $job->workspace?->project;
        if (! $project instanceof Project) {
            $job->loadMissing('workspace.project');
            $project = $job->workspace?->project;
        }
        if (! $project instanceof Project) {
            throw StoryVideoEngineException::invalidInput('The story workspace has no project for private storage.');
        }

        $mime = is_string($inline['mime'] ?? null) && $inline['mime'] !== ''
            ? (string) $inline['mime']
            : 'audio/mpeg';
        $extension = str_contains($mime, 'wav') ? 'wav' : (str_contains($mime, 'ogg') ? 'ogg' : 'mp3');
        $path = $project->uuid.'/'.\Illuminate\Support\Str::uuid()->toString().'.'.$extension;

        try {
            Storage::disk('voice')->put($path, $bytes);
        } catch (Throwable $exception) {
            $job->forceFill([
                'status' => StoryVideoJobStatus::Failed->value,
                'error_code' => 'upstream_error',
                'error_message' => $this->safeMessage($exception),
                'failed_at' => now(),
                'provider_metadata' => array_merge($metadata, [
                    'download_pending' => false,
                    'audio_inline' => null,
                ]),
            ])->save();
            throw StoryVideoEngineException::invalidInput($this->safeMessage($exception));
        }

        if (! Storage::disk('voice')->exists($path)) {
            throw StoryVideoEngineException::invalidInput('Private audio storage failed.');
        }

        $checksum = hash('sha256', $bytes);
        $job->forceFill([
            'status' => StoryVideoJobStatus::Completed->value,
            'completed_at' => now(),
            'provider_metadata' => array_merge($metadata, [
                'download_pending' => false,
                'audio_inline' => null,
                'storage' => [
                    'disk' => 'voice',
                    'path' => $path,
                    'mime' => $mime,
                    'size' => strlen($bytes),
                    'checksum' => $checksum,
                ],
            ]),
        ])->save();

        return new StoryVideoGenerationOutput(
            mediaReference: $path,
            mimeType: $mime,
            providerOutputId: $job->operation_id,
            downloadStrategy: 'private_file',
            checksum: $checksum,
            metadata: ['disk' => 'voice'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function audioHttp(StoryVideoGenerationRequest $request, ?string $operation): array
    {
        $apiKey = (string) config('ai.providers.gemini.api_key', '');
        if ($apiKey === '') {
            throw StoryVideoEngineException::generationNotEnabled();
        }

        $model = $request->preferredModel
            ?: (string) (config('ai.providers.gemini.video_default_model') ?: 'configured-video-model');
        $role = (string) ($request->metadata['audio_role'] ?? 'generated');
        $prompt = trim((string) ($request->prompt ?? ''));
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent';

        try {
            $response = \Illuminate\Support\Facades\Http::withQueryParameters(['key' => $apiKey])
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'contents' => [[
                        'parts' => [[
                            'text' => '['.$role.'] '.$prompt,
                        ]],
                    ]],
                    'generationConfig' => [
                        'responseModalities' => ['AUDIO'],
                    ],
                    'operation' => $operation,
                ]);
        } catch (Throwable $exception) {
            throw StoryVideoEngineException::invalidInput($this->safeMessage($exception));
        }

        if (! $response->successful()) {
            throw StoryVideoEngineException::invalidInput('The audio provider request failed.');
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw StoryVideoEngineException::invalidInput('The audio provider returned an invalid response.');
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{mime: string, data: string}|null
     */
    private function audioInline(array $payload): ?array
    {
        $data = $payload['candidates'][0]['content']['parts'][0]['inlineData']['data']
            ?? $payload['inlineData']['data']
            ?? null;
        $mime = $payload['candidates'][0]['content']['parts'][0]['inlineData']['mimeType']
            ?? $payload['inlineData']['mimeType']
            ?? 'audio/mpeg';

        if (! is_string($data) || $data === '') {
            return null;
        }

        return [
            'mime' => is_string($mime) && $mime !== '' ? $mime : 'audio/mpeg',
            'data' => $data,
        ];
    }

    private function toVideoRequest(StoryVideoGenerationRequest $request, ?string $operation): VideoRequest
    {
        $options = [];
        if ($operation !== null && $operation !== '') {
            $options['operation'] = $operation;
        }
        $image = $this->imagePayload($request);
        if ($image !== null) {
            $options['image'] = $image;
        }
        $video = $this->videoPayload($request);
        if ($video !== null) {
            $options['video'] = $video;
        }

        $seconds = $request->durationSeconds;
        $supported = $this->supportedDurations($request->capability);
        if ($seconds !== null && $supported !== [] && ! in_array($seconds, $supported, true)) {
            $seconds = max($supported);
        }

        return new VideoRequest(
            prompt: (string) ($request->prompt ?? ''),
            model: $request->preferredModel,
            durationSeconds: $seconds !== null ? (float) $seconds : null,
            resolution: $request->resolution,
            aspectRatio: $request->aspectRatio,
            format: 'mp4',
            options: $options,
        );
    }

    /**
     * @return array{mimeType: string, bytesBase64Encoded: string}|null
     */
    private function videoPayload(StoryVideoGenerationRequest $request): ?array
    {
        foreach ($request->inputs as $input) {
            if ($input->type !== StoryVideoInputType::Video) {
                continue;
            }
            $disk = $input->metadata['disk'] ?? null;
            $path = $input->metadata['path'] ?? null;
            $mime = $input->metadata['mime'] ?? null;
            if ($disk !== 'videos' || ! is_string($path) || $path === '' || str_contains($path, '..') || str_contains($path, '://')) {
                continue;
            }
            if (! Storage::disk($disk)->exists($path)) {
                continue;
            }
            $bytes = Storage::disk($disk)->get($path);
            if (! is_string($bytes) || $bytes === '') {
                continue;
            }

            return [
                'mimeType' => is_string($mime) && $mime !== '' ? $mime : 'video/mp4',
                'bytesBase64Encoded' => base64_encode($bytes),
            ];
        }

        return null;
    }

    /**
     * @return array{mimeType: string, bytesBase64Encoded: string}|null
     */
    private function imagePayload(StoryVideoGenerationRequest $request): ?array
    {
        foreach ($request->inputs as $input) {
            if (! in_array($input->type, [StoryVideoInputType::Image, StoryVideoInputType::ReferenceImage], true)) {
                continue;
            }
            $disk = $input->metadata['disk'] ?? null;
            $path = $input->metadata['path'] ?? null;
            $mime = $input->metadata['mime'] ?? null;
            if ($disk !== 'images' || ! is_string($path) || $path === '' || str_contains($path, '..') || str_contains($path, '://')) {
                continue;
            }
            if (! Storage::disk($disk)->exists($path)) {
                continue;
            }
            $bytes = Storage::disk($disk)->get($path);
            if (! is_string($bytes) || $bytes === '') {
                continue;
            }

            return [
                'mimeType' => is_string($mime) && $mime !== '' ? $mime : 'image/png',
                'bytesBase64Encoded' => base64_encode($bytes),
            ];
        }

        return null;
    }

    private function safeUri(string $uri): ?string
    {
        if ($uri === '' || str_contains($uri, 'key=')) {
            return null;
        }

        return $uri;
    }

    /**
     * A poll that could not reach a verdict leaves the job status unchanged;
     * the normalized code decides whether the same operation is polled again.
     */
    private function pollErrorCode(Throwable $exception): StoryVideoErrorCode
    {
        return match (true) {
            $exception instanceof ProviderTimeoutException => StoryVideoErrorCode::Timeout,
            $exception instanceof ProviderNetworkException => StoryVideoErrorCode::ProviderUnavailable,
            $exception instanceof ProviderRateLimitException => StoryVideoErrorCode::RateLimited,
            $exception instanceof ProviderAuthenticationException => StoryVideoErrorCode::AuthenticationFailed,
            $exception instanceof AIException && $exception->statusCode() < 500 => StoryVideoErrorCode::InvalidProviderResponse,
            default => StoryVideoErrorCode::UpstreamError,
        };
    }

    private function safeMessage(Throwable $exception): string
    {
        return $this->safeMessageText($exception->getMessage());
    }

    private function safeMessageText(string $message): string
    {
        $message = preg_replace('/(?:^|[?&\s])(?:key|api_key)=\S+/i', ' [redacted]', $message) ?? $message;

        return mb_substr(ProviderErrorSanitizer::message($message, 'The video provider request failed.'), 0, 300);
    }
}
