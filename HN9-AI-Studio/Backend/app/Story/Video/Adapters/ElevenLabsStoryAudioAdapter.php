<?php

declare(strict_types=1);

namespace App\Story\Video\Adapters;

use App\AI\Contracts\ProviderDispatcherInterface;
use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\Exceptions\AIException;
use App\AI\Exceptions\AllProvidersFailedException;
use App\AI\Exceptions\CircuitOpenException;
use App\AI\Exceptions\NoProviderAvailableException;
use App\AI\Exceptions\ProviderApiException;
use App\AI\Exceptions\ProviderAuthenticationException;
use App\AI\Exceptions\ProviderDisabledException;
use App\AI\Exceptions\ProviderNetworkException;
use App\AI\Exceptions\ProviderNotConfiguredException;
use App\AI\Exceptions\ProviderNotRegisteredException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Execution\DispatchOptions;
use App\AI\Providers\ElevenLabs\ElevenLabsConfig;
use App\AI\Providers\ElevenLabs\ElevenLabsVoiceRegistry;
use App\AI\Requests\VoiceRequest;
use App\AI\Support\ProviderConfigResolver;
use App\Story\Contracts\LiveStoryVideoProviderAdapterInterface;
use App\Story\Contracts\StoryAudioVoiceCatalogInterface;
use App\Story\Enums\StoryVideoAsyncMode;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Video\CatalogStoryVideoAdapter;
use App\Story\Video\StoryVideoGenerationOutput;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoSubmission;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Scene sound through the shared ElevenLabs text-to-speech provider. Speech is
 * synthesised through the provider dispatcher pinned to ElevenLabs, so the
 * shared retry, circuit breaker and metrics apply and no other provider is tried.
 *
 * Synthesis answers immediately: submit() keeps the audio in a private pending
 * file, and the regular status/result steps move it into project storage.
 */
final readonly class ElevenLabsStoryAudioAdapter implements LiveStoryVideoProviderAdapterInterface, StoryAudioVoiceCatalogInterface
{
    public const VENDOR = 'elevenlabs';

    private const DISK = 'voice';

    private const PENDING_DIR = 'pending';

    private const PREFERRED_FORMAT = 'mp3_44100_128';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private CatalogStoryVideoAdapter $catalog,
        private ProviderDispatcherInterface $dispatcher,
        private ProviderRegistryInterface $registry,
        private ProviderConfigResolver $configs,
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

    /**
     * Connected only when the shared ElevenLabs provider is registered, enabled
     * and has a voice to speak with.
     */
    public function enabled(): bool
    {
        if (! $this->registry->has(ElevenLabsConfig::KEY)) {
            return false;
        }

        try {
            if (! $this->registry->get(ElevenLabsConfig::KEY)->isEnabled()) {
                return false;
            }
            $voices = $this->voices();

            return $voices->voiceNames() !== [] || $this->elevenLabsConfig()->defaultVoice !== null;
        } catch (Throwable) {
            return false;
        }
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
        try {
            return $this->voices()->voiceNames();
        } catch (Throwable) {
            return [];
        }
    }

    public function defaultVoiceName(): ?string
    {
        try {
            $config = $this->elevenLabsConfig();
            if ($config->defaultVoice === null) {
                return null;
            }

            return $this->voices()->voiceName($this->voices()->resolveVoice($config->defaultVoice)) ?? $config->defaultVoice;
        } catch (Throwable) {
            return null;
        }
    }

    public function validate(StoryVideoGenerationRequest $request): void
    {
        $this->catalog->validate($request);
        $text = trim((string) $request->prompt);
        if ($text === '') {
            throw StoryVideoEngineException::invalidInput('Write the words that should be spoken.');
        }
        $limit = max(1, (int) ($this->config['max_characters'] ?? 5000));
        if (mb_strlen($text) > $limit) {
            throw StoryVideoEngineException::invalidInput('Keep the text under '.number_format($limit).' characters.');
        }
        $voice = $request->metadata['voice'] ?? null;
        if (is_string($voice) && $voice !== '' && ! in_array(strtolower($voice), array_map('strtolower', $this->voiceNames()), true)) {
            throw StoryVideoEngineException::invalidInput('Choose one of the available voices.');
        }
    }

    public function submit(StoryVideoGenerationRequest $request): StoryVideoSubmission
    {
        $this->validate($request);
        $voice = $request->metadata['voice'] ?? null;

        try {
            $response = $this->dispatcher->voice(
                new VoiceRequest(
                    input: trim((string) $request->prompt),
                    voice: is_string($voice) && $voice !== '' ? $voice : null,
                    format: $this->format(),
                ),
                DispatchOptions::only(ElevenLabsConfig::KEY),
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

        $operation = (string) $job->operation_id;
        if ($operation === '') {
            return StoryVideoJobStatus::tryFrom((string) $job->status) ?? StoryVideoJobStatus::Queued;
        }

        if ($this->pendingPath($operation) === null || ! Storage::disk(self::DISK)->exists($this->pendingPath($operation))) {
            $job->forceFill([
                'status' => StoryVideoJobStatus::Failed->value,
                'error_code' => StoryVideoErrorCode::DownloadFailed->value,
                'error_message' => 'The sound could not be saved. Try again.',
                'failed_at' => now(),
            ])->save();

            return StoryVideoJobStatus::Failed;
        }

        $job->forceFill([
            'status' => StoryVideoJobStatus::Processing->value,
            'provider_metadata' => array_merge((array) $job->provider_metadata, ['download_pending' => true]),
        ])->save();

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
            $job->forceFill([
                'status' => StoryVideoJobStatus::Failed->value,
                'error_code' => StoryVideoErrorCode::DownloadFailed->value,
                'error_message' => 'The sound could not be saved. Try again.',
                'failed_at' => now(),
                'provider_metadata' => array_merge((array) $job->provider_metadata, ['download_pending' => false]),
            ])->save();

            throw StoryVideoEngineException::provider(StoryVideoErrorCode::DownloadFailed, 'The sound could not be saved. Try again.');
        }

        $mime = $this->mimeFor($path);
        $checksum = hash('sha256', $bytes);
        $job->forceFill([
            'status' => StoryVideoJobStatus::Completed->value,
            'completed_at' => now(),
            'error_code' => null,
            'error_message' => null,
            'provider_metadata' => array_merge((array) $job->provider_metadata, [
                'download_pending' => false,
                'storage' => [
                    'disk' => self::DISK,
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
            metadata: ['disk' => self::DISK],
        );
    }

    private function failure(Throwable $exception): StoryVideoEngineException
    {
        if ($exception instanceof StoryVideoEngineException) {
            return $exception;
        }
        if ($exception instanceof AllProvidersFailedException && $exception->getPrevious() !== null) {
            $exception = $exception->getPrevious();
        }

        [$code, $message] = match (true) {
            $exception instanceof ProviderAuthenticationException => [
                StoryVideoErrorCode::AuthenticationFailed,
                'The sound service rejected the saved credentials. An administrator needs to check them.',
            ],
            $exception instanceof ProviderRateLimitException => [
                StoryVideoErrorCode::RateLimited,
                'The sound service is busy right now. Try again in a moment.',
            ],
            $exception instanceof ProviderTimeoutException => [
                StoryVideoErrorCode::Timeout,
                'The sound service took too long to answer. Try again.',
            ],
            $exception instanceof ProviderNetworkException, $exception instanceof CircuitOpenException => [
                StoryVideoErrorCode::ProviderUnavailable,
                'The sound service could not be reached. Try again later.',
            ],
            $exception instanceof ProviderNotConfiguredException,
            $exception instanceof ProviderNotRegisteredException,
            $exception instanceof ProviderDisabledException,
            $exception instanceof NoProviderAvailableException => [
                StoryVideoErrorCode::GenerationNotEnabled,
                'Sound generation is not configured yet.',
            ],
            $exception instanceof ProviderApiException && $exception->statusCode() === 402 => [
                StoryVideoErrorCode::QuotaExceeded,
                'The sound service account has no remaining credit.',
            ],
            $exception instanceof ProviderApiException && $exception->statusCode() >= 400 && $exception->statusCode() < 500 => [
                StoryVideoErrorCode::InvalidInput,
                'The sound service could not use this text. Change the words and try again.',
            ],
            $exception instanceof AIException => [
                StoryVideoErrorCode::UpstreamError,
                'The sound service is temporarily unavailable. Try again later.',
            ],
            default => [
                StoryVideoErrorCode::UnknownProviderError,
                'Sound generation failed. Try again.',
            ],
        };

        return StoryVideoEngineException::provider($code, $message);
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
            str_contains($mime, 'ogg') || str_contains($mime, 'opus') => 'ogg',
            str_contains($mime, 'mpeg') || str_contains($mime, 'mp3') => 'mp3',
            default => null,
        };

        return $extension === null ? null : ['bytes' => $bytes, 'extension' => $extension];
    }

    private function pendingPath(string $operationId): ?string
    {
        return preg_match('/^[0-9a-f-]{36}\.(mp3|wav|ogg)$/', $operationId) === 1
            ? self::PENDING_DIR.'/'.$operationId
            : null;
    }

    private function mimeFor(string $path): string
    {
        return match (pathinfo($path, PATHINFO_EXTENSION)) {
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            default => 'audio/mpeg',
        };
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

    /**
     * MP3 plays in every browser and in the final video builder; another format is
     * used only when an allow-list in configuration excludes it.
     */
    private function format(): ?string
    {
        $allowed = $this->elevenLabsConfig()->outputFormats;

        return $allowed === [] || in_array(self::PREFERRED_FORMAT, $allowed, true) ? self::PREFERRED_FORMAT : null;
    }

    private function voices(): ElevenLabsVoiceRegistry
    {
        return new ElevenLabsVoiceRegistry($this->elevenLabsConfig());
    }

    private function elevenLabsConfig(): ElevenLabsConfig
    {
        return ElevenLabsConfig::fromProviderConfig($this->configs->resolve(ElevenLabsConfig::KEY));
    }
}
