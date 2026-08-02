<?php

declare(strict_types=1);

namespace App\AI\Providers\ElevenLabs;

use App\AI\Exceptions\ProviderNotConfiguredException;
use App\AI\Support\AbstractModelRegistry;
use App\AI\Support\ConfigNormalizer;

/**
 * ElevenLabs vocabulary bookkeeping: the text-to-speech models, the voices and
 * the output formats a deployment has configured, plus the resolution of a
 * requested value against them.
 *
 * Voices carry an extra concern the model registry does not: the vendor
 * addresses them by opaque identifier, while a studio thinks in names. Both are
 * therefore accepted — a configured name (case-insensitively) or the identifier
 * itself — so callers may ask for a voice however they know it. No voice, model
 * or format is named in this class; a voice added to the account tomorrow is
 * adopted by configuration alone.
 */
final readonly class ElevenLabsVoiceRegistry extends AbstractModelRegistry
{
    public function __construct(private ElevenLabsConfig $config)
    {
        parent::__construct(ElevenLabsConfig::KEY, $config->models, $config->defaultModel);
    }

    /**
     * The configured voices as `name => voice id`.
     *
     * @return array<string, string>
     */
    public function voices(): array
    {
        return $this->config->voices;
    }

    /**
     * @return list<string>
     */
    public function voiceNames(): array
    {
        return array_keys($this->config->voices);
    }

    /**
     * @return list<string>
     */
    public function voiceIds(): array
    {
        return array_values(array_unique(array_values($this->config->voices)));
    }

    /**
     * Resolve a requested voice — a configured name or a configured identifier —
     * to the identifier the vendor expects, falling back to the configured
     * default voice.
     */
    public function resolveVoice(?string $voice): string
    {
        $requested = ConfigNormalizer::nonEmptyString($voice) ?? $this->config->defaultVoice;

        if ($requested === null) {
            throw ProviderNotConfiguredException::forKey($this->providerKey);
        }

        foreach ($this->config->voices as $name => $id) {
            if (strcasecmp($name, $requested) === 0 || $id === $requested) {
                return $id;
            }
        }

        throw ProviderNotConfiguredException::forKey($this->providerKey);
    }

    /**
     * The configured name for an identifier, for reporting. Null when the voice
     * is addressed by identifier without a name of its own.
     */
    public function voiceName(string $voiceId): ?string
    {
        $name = array_search($voiceId, $this->config->voices, true);

        return is_string($name) ? $name : null;
    }

    /**
     * Resolve the output format, falling back to the configured default.
     *
     * Formats are validated only when an allow-list is configured. Unlike a
     * model or a voice — where an unknown value is a billing and routing risk —
     * an unknown format is a presentation detail the vendor rejects cheaply, so
     * an empty allow-list stays permissive rather than forcing every deployment
     * to enumerate the vendor's format catalogue.
     */
    public function resolveFormat(?string $format): string
    {
        $requested = ConfigNormalizer::nonEmptyString($format) ?? $this->config->outputFormat;

        if ($requested === null) {
            throw ProviderNotConfiguredException::forKey($this->providerKey);
        }

        if ($this->config->outputFormats !== [] && ! in_array($requested, $this->config->outputFormats, true)) {
            throw ProviderNotConfiguredException::forKey($this->providerKey);
        }

        return $requested;
    }
}
