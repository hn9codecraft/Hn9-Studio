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
    // xAI defaults are the flat rates on https://docs.x.ai/developers/pricing (24 Nov 2026).
    // Video is not split by resolution on that page. Text uses separate input and output rates.
    'rates_note' => 'Estimates only. Verify on provider pricing pages. xAI: https://docs.x.ai/developers/pricing',
    'video' => [
        'video.runway' => [
            'gen4.5' => $usd('STORY_COST_RUNWAY_GEN45_PER_SECOND', 0.12),
            'aleph2' => $usd('STORY_COST_RUNWAY_ALEPH2_PER_SECOND', 0.28),
        ],
        'video.luma' => ['ray-3.2' => $usd('STORY_COST_LUMA_PER_SECOND')],
        'video.seedance' => ['dreamina-seedance-2-0-260128' => $usd('STORY_COST_SEEDANCE_PER_SECOND')],
        'video.xai' => [
            'grok-imagine-video-1.5-lite' => $usd('STORY_COST_XAI_VIDEO_LITE_PER_SECOND', 0.02),
            'grok-imagine-video-1.5' => $usd('STORY_COST_XAI_VIDEO_15_PER_SECOND', 0.08),
        ],
    ],
    'audio' => [
        'audio.elevenlabs' => $usd('STORY_COST_ELEVENLABS_PER_1K_CHARS', 0.08),
        'audio.xai' => $usd('STORY_COST_XAI_TTS_PER_1K_CHARS', 0.015),
    ],
    'image' => [
        'default' => $usd('STORY_COST_IMAGE_PER_IMAGE'),
        'xai' => [
            'grok-imagine-image' => $usd('STORY_COST_XAI_IMAGE_PER_IMAGE', 0.02),
            'grok-imagine-image-2.0' => $usd('STORY_COST_XAI_IMAGE_2_PER_IMAGE', 0.04),
        ],
    ],
    'xai_text' => [
        'input_per_1m' => $usd('STORY_COST_XAI_TEXT_INPUT_PER_1M_TOKENS', 1.25),
        'output_per_1m' => $usd('STORY_COST_XAI_TEXT_OUTPUT_PER_1M_TOKENS', 2.50),
    ],
    // Null until a text price is configured. Never guessed.
    'text' => [
        'per_1k_tokens' => $usd('STORY_COST_TEXT_PER_1K_TOKENS'),
    ],
    'safety_margin' => (float) env('STORY_COST_SAFETY_MARGIN', 0.10),
];
