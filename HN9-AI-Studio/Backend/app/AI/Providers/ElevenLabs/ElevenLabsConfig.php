<?php

declare(strict_types=1);

namespace App\AI\Providers\ElevenLabs;

use App\AI\DTOs\ProviderConfigDTO;
use App\AI\Exceptions\ProviderNotConfiguredException;
use App\AI\Support\ConfigNormalizer;

/**
 * Resolved, validated ElevenLabs settings. Every value originates in
 * `config/ai.php` (and therefore the environment) — no credential, endpoint,
 * model identifier, voice, output format or price is hard-coded here.
 *
 * Voices are the distinguishing concern: they are studio assets that differ per
 * deployment (stock voices, custom voices, voices that do not exist yet), so
 * they are configured as a `name => voice id` map and never compiled in.
 */
final readonly class ElevenLabsConfig
{
    public const KEY = 'elevenlabs';

    /**
     * The vendor spelling each accepted voice-setting key normalises to. Both
     * the vendor's own names and the studio's shorter aliases are accepted,
     * because the shared VoiceRequest contract carries these in `options` and
     * callers should not have to know the vendor's spelling.
     */
    public const VOICE_SETTING_ALIASES = [
        'stability' => 'stability',
        'similarity' => 'similarity_boost',
        'similarity_boost' => 'similarity_boost',
        'style' => 'style',
        'speaker_boost' => 'use_speaker_boost',
        'use_speaker_boost' => 'use_speaker_boost',
        'speed' => 'speed',
    ];

    /**
     * The one voice setting the vendor types as a boolean; the rest are floats.
     */
    private const BOOLEAN_VOICE_SETTING = 'use_speaker_boost';

    /**
     * @param  list<string>  $models  Text-to-speech model identifiers.
     * @param  array<string, string>  $voices  Friendly name => voice id.
     * @param  list<string>  $outputFormats  Permitted output formats; empty means unrestricted.
     * @param  array<string, float|bool>  $voiceSettings  Default synthesis settings.
     * @param  array<string, float|int>  $creditMultipliers  Credits charged per character, keyed by model.
     * @param  array<string, array{input?: float|int, output?: float|int}>  $pricing
     */
    public function __construct(
        public string $apiKey,
        public string $baseUrl,
        public ?string $defaultModel,
        public int $timeout,
        public int $maxRetries,
        public array $models,
        public array $voices,
        public ?string $defaultVoice,
        public ?string $outputFormat,
        public array $outputFormats,
        public array $voiceSettings,
        public array $creditMultipliers,
        public bool $supportsStreaming,
        public array $pricing,
    ) {}

    public static function fromProviderConfig(ProviderConfigDTO $config): self
    {
        $apiKey = ConfigNormalizer::nonEmptyString($config->option('api_key'));
        $baseUrl = ConfigNormalizer::nonEmptyString($config->baseUrl);

        if ($apiKey === null || $baseUrl === null) {
            throw ProviderNotConfiguredException::forKey(self::KEY);
        }

        $multipliers = $config->option('credit_multipliers', []);
        $pricing = $config->option('pricing', []);

        return new self(
            apiKey: $apiKey,
            baseUrl: rtrim($baseUrl, '/'),
            defaultModel: ConfigNormalizer::nonEmptyString($config->defaultModel),
            timeout: $config->timeout,
            maxRetries: $config->maxRetries,
            models: ConfigNormalizer::stringList($config->option('models', [])),
            voices: ConfigNormalizer::keyedMap($config->option('voices', [])),
            defaultVoice: ConfigNormalizer::nonEmptyString($config->option('default_voice')),
            outputFormat: ConfigNormalizer::nonEmptyString($config->option('output_format')),
            outputFormats: ConfigNormalizer::stringList($config->option('output_formats', [])),
            voiceSettings: self::normalizeVoiceSettings($config->option('voice_settings', [])),
            creditMultipliers: self::numericMap($multipliers),
            supportsStreaming: (bool) $config->option('supports_streaming', false),
            pricing: is_array($pricing) ? $pricing : [],
        );
    }

    /**
     * Coerce a loose settings array into the vendor's spellings and types,
     * dropping anything unrecognised. Shared by the configured defaults and by
     * per-request overrides so both are normalised identically, in one place.
     *
     * @return array<string, float|bool>
     */
    public static function normalizeVoiceSettings(mixed $settings): array
    {
        if (! is_array($settings)) {
            return [];
        }

        $normalized = [];

        foreach ($settings as $key => $value) {
            $name = is_string($key) ? self::VOICE_SETTING_ALIASES[strtolower(trim($key))] ?? null : null;

            if ($name === null) {
                continue;
            }

            if ($name === self::BOOLEAN_VOICE_SETTING) {
                $normalized[$name] = is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL);

                continue;
            }

            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                $normalized[$name] = (float) $value;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, float|int>
     */
    private static function numericMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $entry) {
            if (is_string($key) && $key !== '' && (is_int($entry) || is_float($entry))) {
                $map[$key] = $entry;
            }
        }

        return $map;
    }
}
