<?php

declare(strict_types=1);

return [
    'timestamps_enabled' => (bool) env('STORY_VOICE_TIMESTAMPS_ENABLED', true),
    'disk' => env('STORY_SUBTITLE_DISK', 'exports'),
    'max_chars_per_line' => ['16:9' => 42, '9:16' => 30],
    'max_lines' => 2,
    'min_cue_ms' => 800,
    'max_cue_ms' => 6000,
    'gap_ms' => 80,
    'burn' => [
        'font_name' => env('STORY_SUBTITLE_FONT_NAME', 'Nirmala UI'),
        'font_file' => env('STORY_SUBTITLE_FONT_FILE', PHP_OS_FAMILY === 'Windows' ? 'C:/Windows/Fonts/Nirmala.ttc' : null),
        'font_size' => (int) env('STORY_SUBTITLE_FONT_SIZE', 20),
        'margin_v' => (int) env('STORY_SUBTITLE_MARGIN_V', 24),
        'outline' => 2,
        'crf' => (int) env('STORY_SUBTITLE_BURN_CRF', 20),
        'preset' => env('STORY_SUBTITLE_BURN_PRESET', 'veryfast'),
        'timeout' => (int) env('STORY_SUBTITLE_BURN_TIMEOUT', 900),
    ],
];
