<?php

declare(strict_types=1);

namespace App\AI\Providers\ElevenLabs;

use App\AI\DTOs\ProviderConfigDTO;
use App\AI\DTOs\ProviderHealthDTO;
use App\AI\DTOs\ProviderRequestDTO;
use App\AI\Exceptions\AIException;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\AI\Providers\AbstractProvider;
use App\AI\Requests\VoiceRequest;
use App\AI\Responses\TokenResponse;
use App\AI\Responses\VoiceResponse;
use App\AI\Support\Capability;
use App\AI\Support\HealthStatus;
use Throwable;

/**
 * ElevenLabs adapter — text-to-speech only.
 *
 * This is the studio's first voice provider, and the first whose vendor answers
 * with binary rather than JSON. Both fit the existing abstraction without
 * changing it: synthesis returns the shared {@see VoiceResponse}, whose audio
 * is carried as a data URI in the same way inline image output already is.
 *
 * Text, image and video inherit {@see AbstractProvider}'s unsupported-capability
 * behaviour deliberately — this adapter speaks one modality and does not
 * approximate the others. `countTokens()` is likewise reported unsupported:
 * ElevenLabs meters characters and has no token concept, and reporting
 * characters as tokens would misstate what the vendor measures.
 *
 * The account's other routes — cloning, dubbing, sound effects, speech-to-text,
 * conversational AI — are out of scope for this sprint and are not declared.
 */
final class ElevenLabsProvider extends AbstractProvider
{
    public const VERSION = '1.0.0';

    private const KEY = ElevenLabsConfig::KEY;

    public function __construct(
        private readonly ElevenLabsClient $client,
        private readonly ElevenLabsVoiceRegistry $voices,
        private readonly ElevenLabsUsageCalculator $usageCalculator,
        private readonly ElevenLabsResponseNormalizer $normalizer,
        private readonly ElevenLabsTokenCounter $tokenCounter,
        private readonly ElevenLabsConfig $elevenLabsConfig,
    ) {
        parent::__construct(new ProviderConfigDTO(
            self::KEY,
            $elevenLabsConfig->baseUrl,
            $elevenLabsConfig->defaultModel,
            $elevenLabsConfig->timeout,
            $elevenLabsConfig->maxRetries,
        ));
    }

    public function providerName(): string
    {
        return self::KEY;
    }

    public function providerVersion(): string
    {
        return self::VERSION;
    }

    public function generateVoice(VoiceRequest $request): VoiceResponse
    {
        $model = $this->voices->resolve($request->model);
        $voiceId = $this->voices->resolveVoice($request->voice);
        $format = $this->voices->resolveFormat($request->format);
        $characters = $this->tokenCounter->characters($request->input);

        $startedAt = hrtime(true);
        $audio = $this->client->speech(
            $voiceId,
            $this->speechPayload($request, $model),
            ['output_format' => $format],
        );

        return $this->normalizer->voice(
            audio: $audio,
            model: $model,
            voiceId: $voiceId,
            voiceName: $this->voices->voiceName($voiceId),
            format: $format,
            characters: $characters,
            executionTimeMs: $this->elapsedMilliseconds($startedAt),
        );
    }

    public function estimateCost(ProviderRequestDTO $request): float
    {
        $model = $this->voices->resolve($request->model);
        $characters = $this->tokenCounter->characters((string) ($request->parameters['input'] ?? ''));

        return $this->usageCalculator->fromCharacters($characters, $model)->cost;
    }

    /**
     * ElevenLabs bills characters and publishes no tokenizer, so there is no
     * token count to report. Characters are available through
     * {@see ElevenLabsTokenCounter::characters()} and are carried on every
     * response's usage and raw payload.
     */
    public function countTokens(string $text, ?string $model = null): TokenResponse
    {
        throw UnsupportedCapabilityException::make(self::KEY, Capability::Text);
    }

    /**
     * The configured text-to-speech models.
     */
    public function supportedModels(): array
    {
        return $this->voices->all();
    }

    /**
     * The configured voices, as `name => voice id`.
     *
     * @return array<string, string>
     */
    public function supportedVoices(): array
    {
        return $this->voices->voices();
    }

    /**
     * ElevenLabs streams over a dedicated route; the shared provider contract is
     * synchronous, so this reports the configured capability without altering
     * the request.
     */
    public function supportsStreaming(): bool
    {
        return $this->elevenLabsConfig->supportsStreaming;
    }

    /**
     * Probes connectivity and authentication against the subscription endpoint,
     * then verifies the configured voice and model. Every call is read-only — no
     * audio is synthesised and no characters are billed.
     *
     * An authenticated account whose voice or model cannot be verified is
     * degraded rather than unavailable: the credential works and the rest of the
     * configuration may still serve.
     */
    public function healthCheck(): ProviderHealthDTO
    {
        $checkedAt = now()->toIso8601String();

        try {
            $model = $this->voices->resolve(null);
            $voiceId = $this->voices->resolveVoice(null);

            $startedAt = hrtime(true);
            $subscription = $this->client->subscription();
            $latencyMs = $this->elapsedMilliseconds($startedAt);

            $voiceVerified = $this->verifyVoice($voiceId);
            $modelVerified = $this->verifyModel($model);
            $unverified = array_keys(array_filter(['voice' => ! $voiceVerified, 'model' => ! $modelVerified]));

            return new ProviderHealthDTO(
                key: self::KEY,
                status: $unverified === [] ? HealthStatus::Healthy : HealthStatus::Degraded,
                latencyMs: $latencyMs,
                message: $unverified === []
                    ? null
                    : 'Configured '.implode(' and ', $unverified).' could not be verified with ElevenLabs.',
                checkedAt: $checkedAt,
                details: [
                    'default_model' => $model,
                    'model_verified' => $modelVerified,
                    'default_voice' => $voiceId,
                    'default_voice_name' => $this->voices->voiceName($voiceId),
                    'voice_verified' => $voiceVerified,
                    'voices_configured' => count($this->voices->voices()),
                    'models_configured' => count($this->voices->all()),
                    'output_format' => $this->elevenLabsConfig->outputFormat,
                    ...$this->quota($subscription),
                ],
            );
        } catch (Throwable $exception) {
            return ProviderHealthDTO::unavailable(self::KEY, $exception->getMessage(), $checkedAt);
        }
    }

    private function verifyVoice(string $voiceId): bool
    {
        try {
            return ($this->client->voice($voiceId)['voice_id'] ?? null) === $voiceId;
        } catch (AIException) {
            return false;
        }
    }

    private function verifyModel(string $model): bool
    {
        try {
            foreach ($this->client->models() as $entry) {
                if (is_array($entry) && ($entry['model_id'] ?? null) === $model) {
                    return true;
                }
            }
        } catch (AIException) {
            return false;
        }

        return false;
    }

    /**
     * The character allowance worth surfacing on a health report. Absent fields
     * are omitted rather than defaulted, so the report never overstates what the
     * vendor returned.
     *
     * @param  array<string, mixed>  $subscription
     * @return array<string, mixed>
     */
    private function quota(array $subscription): array
    {
        return array_filter([
            'tier' => is_string($subscription['tier'] ?? null) ? $subscription['tier'] : null,
            'characters_used' => is_int($subscription['character_count'] ?? null) ? $subscription['character_count'] : null,
            'character_limit' => is_int($subscription['character_limit'] ?? null) ? $subscription['character_limit'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function speechPayload(VoiceRequest $request, string $model): array
    {
        $settings = $this->voiceSettings($request);

        $payload = array_filter([
            'text' => $request->input,
            'model_id' => $model,
            'language_code' => $request->language,
            'voice_settings' => $settings === [] ? null : $settings,
        ], static fn (mixed $value): bool => $value !== null);

        return [...$payload, ...$this->passthroughOptions($request)];
    }

    /**
     * The configured default settings, overlaid with any the request carries.
     *
     * Stability, similarity, style and speaker boost have no field on the shared
     * {@see VoiceRequest}, so they arrive through its `options` bag — either
     * nested under `voice_settings` or as loose keys. Speed does have a field,
     * and being the explicit contract it wins over the same key in `options`.
     *
     * @return array<string, float|bool>
     */
    private function voiceSettings(VoiceRequest $request): array
    {
        $overrides = ElevenLabsConfig::normalizeVoiceSettings([
            ...$request->options,
            ...(is_array($request->options['voice_settings'] ?? null) ? $request->options['voice_settings'] : []),
            ...($request->speed === null ? [] : ['speed' => $request->speed]),
        ]);

        return [...$this->elevenLabsConfig->voiceSettings, ...$overrides];
    }

    /**
     * Vendor parameters the shared contract does not model — a seed, a
     * pronunciation dictionary, text normalisation — pass through untouched.
     * Keys already consumed as voice settings are removed so they cannot also
     * appear at the top level of the payload.
     *
     * @return array<string, mixed>
     */
    private function passthroughOptions(VoiceRequest $request): array
    {
        return array_diff_key(
            $request->options,
            ElevenLabsConfig::VOICE_SETTING_ALIASES,
            ['voice_settings' => null],
        );
    }
}
