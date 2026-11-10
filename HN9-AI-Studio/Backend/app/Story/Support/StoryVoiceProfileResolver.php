<?php

declare(strict_types=1);

namespace App\Story\Support;

use App\Story\Exceptions\StoryException;

/**
 * Picks an ElevenLabs model and voice for English, Hindi or Gujarati.
 * Gujarati is never sent to a model that is known not to support it.
 */
final class StoryVoiceProfileResolver
{
    /** @var list<string> */
    public const CODES = ['en', 'hi', 'gu'];

    public function resolve(?string $requestLanguage, ?string $bibleLanguage): StoryVoiceProfile
    {
        $language = $this->language($requestLanguage, $bibleLanguage);
        $model = $this->model($language);
        $known = config('story_voice.model_languages.'.$model);
        $verified = is_array($known);
        if ($verified && ! in_array($language, $known, true)) {
            throw new StoryException(
                $language === 'gu'
                    ? 'Gujarati needs eleven_v3 or newer; set ELEVENLABS_MODEL_GU'
                    : 'That voice model does not support this language.',
                'story_voice_language_unsupported',
                422,
            );
        }

        $configuredVoice = $this->configuredVoice($language);
        $voiceId = $configuredVoice ?? $this->nonEmpty(config('ai.providers.elevenlabs.default_voice'));
        $languageCodeModels = config('story_voice.language_code_models');
        $languageCodeModels = is_array($languageCodeModels) ? $languageCodeModels : [];

        return new StoryVoiceProfile(
            language: $language,
            model: $model,
            voiceId: $voiceId,
            sendLanguageCode: in_array($model, $languageCodeModels, true),
            voiceSource: $configuredVoice !== null ? 'language' : 'default',
            modelVerified: $verified,
        );
    }

    /**
     * @return list<array{code: string, label: string, model: string, model_verified: bool, voice_configured: bool, send_language_code: bool}>
     */
    public function catalog(): array
    {
        $rows = [];
        foreach (self::CODES as $code) {
            $profile = $this->resolve($code, null);
            $label = config('story_voice.languages.'.$code.'.label');
            $rows[] = [
                'code' => $code,
                'label' => is_string($label) && $label !== '' ? $label : $code,
                'model' => $profile->model,
                'model_verified' => $profile->modelVerified,
                'voice_configured' => $profile->voiceSource === 'language',
                'send_language_code' => $profile->sendLanguageCode,
            ];
        }

        return $rows;
    }

    public function defaultLanguage(): string
    {
        $configured = $this->nonEmpty(config('story_voice.default_language'));

        return in_array($configured, self::CODES, true) ? $configured : 'en';
    }

    private function language(?string $requestLanguage, ?string $bibleLanguage): string
    {
        foreach ([$requestLanguage, $bibleLanguage, $this->defaultLanguage()] as $candidate) {
            $value = $this->nonEmpty($candidate);
            if ($value !== null && in_array($value, self::CODES, true)) {
                return $value;
            }
        }

        return 'en';
    }

    private function model(string $language): string
    {
        $configured = $this->nonEmpty(config('story_voice.languages.'.$language.'.model'));
        if ($configured !== null) {
            return $configured;
        }
        if ($language === 'en') {
            return $this->nonEmpty(config('ai.providers.elevenlabs.default_model'))
                ?? $this->nonEmpty(config('story_voice.multilingual_model'))
                ?? 'eleven_multilingual_v2';
        }
        if ($language === 'gu') {
            return $this->nonEmpty(config('story_voice.wide_language_model')) ?? 'eleven_v3';
        }

        return $this->nonEmpty(config('story_voice.multilingual_model')) ?? 'eleven_multilingual_v2';
    }

    private function configuredVoice(string $language): ?string
    {
        return $this->nonEmpty(config('story_voice.languages.'.$language.'.voice_id'));
    }

    private function nonEmpty(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
