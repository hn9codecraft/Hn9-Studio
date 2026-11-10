<?php

declare(strict_types=1);

return [
    'default_language' => env('STORY_VOICE_DEFAULT_LANGUAGE', 'en'),
    'languages' => [
        'en' => ['label' => 'English', 'model' => env('ELEVENLABS_MODEL_EN'), 'voice_id' => env('ELEVENLABS_VOICE_EN')],
        'hi' => ['label' => 'Hindi', 'model' => env('ELEVENLABS_MODEL_HI'), 'voice_id' => env('ELEVENLABS_VOICE_HI')],
        'gu' => ['label' => 'Gujarati', 'model' => env('ELEVENLABS_MODEL_GU'), 'voice_id' => env('ELEVENLABS_VOICE_GU')],
    ],
    'multilingual_model' => env('ELEVENLABS_MULTILINGUAL_MODEL', 'eleven_multilingual_v2'),
    'wide_language_model' => env('ELEVENLABS_WIDE_LANGUAGE_MODEL', 'eleven_v3'),
    // models that accept the language_code request field
    'language_code_models' => array_filter(array_map('trim', explode(',', (string) env(
        'ELEVENLABS_LANGUAGE_CODE_MODELS',
        'eleven_v3,eleven_v4,eleven_v4_turbo,eleven_flash_v2_5,eleven_turbo_v2_5',
    )))),
    // languages each model is known to support (documented by ElevenLabs, Nov 2026)
    'model_languages' => [
        'eleven_multilingual_v2' => ['en', 'hi'],
        'eleven_v3' => ['en', 'hi', 'gu'],
        'eleven_v4' => ['en', 'hi', 'gu'],
        'eleven_v4_turbo' => ['en', 'hi', 'gu'],
        'eleven_flash_v2_5' => ['en', 'hi'],
        'eleven_turbo_v2_5' => ['en', 'hi'],
    ],
];
