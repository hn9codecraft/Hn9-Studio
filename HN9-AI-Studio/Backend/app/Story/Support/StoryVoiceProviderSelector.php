<?php

declare(strict_types=1);

namespace App\Story\Support;

/**
 * Picks the voice vendor for one language. Gujarati stays on ElevenLabs
 * unless XAI_TTS_GUJARATI_EXPERIMENTAL is on.
 */
final class StoryVoiceProviderSelector
{
    public function providerFor(?string $language): string
    {
        $code = $this->language($language);
        $configured = strtolower(trim((string) config('story_voice.providers.'.$code, 'elevenlabs')));
        if ($configured === '' || $configured === 'default') {
            $configured = 'elevenlabs';
        }
        if (! in_array($configured, ['elevenlabs', 'xai'], true)) {
            $configured = 'elevenlabs';
        }
        if ($configured === 'xai' && $code === 'gu' && ! $this->gujaratiExperimental()) {
            return 'elevenlabs';
        }

        return $configured;
    }

    public function adapterKey(?string $language): string
    {
        return 'audio.'.$this->providerFor($language);
    }

    public function gujaratiExperimental(): bool
    {
        return filter_var(config('story_voice.xai_gujarati_experimental', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function language(?string $language): string
    {
        $value = strtolower(trim((string) $language));
        if (in_array($value, StoryVoiceProfileResolver::CODES, true)) {
            return $value;
        }
        $fallback = strtolower(trim((string) config('story_voice.default_language', 'en')));

        return in_array($fallback, StoryVoiceProfileResolver::CODES, true) ? $fallback : 'en';
    }
}
