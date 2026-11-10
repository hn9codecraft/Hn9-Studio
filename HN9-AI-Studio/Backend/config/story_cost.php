<?php

declare(strict_types=1);

$usd = static function (string $key, mixed $default = null): ?float {
    $value = env($key, $default);
    if ($value === null || $value === '') {
        return null;
    }

    return (float) $value;
};

return [
    'currency' => 'USD',
    'display_currency' => env('STORY_COST_DISPLAY_CURRENCY', 'INR'),
    'fx' => ['USD_INR' => (float) env('STORY_COST_USD_INR', 94.0)],
    'rates_note' => 'Estimates only. Verify on provider pricing pages.',
    'video' => [
        'video.runway' => [
            'gen4.5' => $usd('STORY_COST_RUNWAY_GEN45_PER_SECOND', 0.12),
            'aleph2' => $usd('STORY_COST_RUNWAY_ALEPH2_PER_SECOND', 0.28),
        ],
        'video.luma' => ['ray-3.2' => $usd('STORY_COST_LUMA_PER_SECOND')],
        'video.seedance' => ['dreamina-seedance-2-0-260128' => $usd('STORY_COST_SEEDANCE_PER_SECOND')],
    ],
    'audio' => [
        'audio.elevenlabs' => $usd('STORY_COST_ELEVENLABS_PER_1K_CHARS', 0.08),
    ],
    'image' => [
        'default' => $usd('STORY_COST_IMAGE_PER_IMAGE'),
    ],
    // Null until a text price is configured. Never guessed.
    'text' => [
        'per_1k_tokens' => $usd('STORY_COST_TEXT_PER_1K_TOKENS'),
    ],
    'safety_margin' => (float) env('STORY_COST_SAFETY_MARGIN', 0.10),
];
