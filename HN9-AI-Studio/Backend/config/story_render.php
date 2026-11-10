<?php

declare(strict_types=1);

return [
    'queue' => [
        'enabled' => (bool) env('STORY_RENDER_QUEUE_ENABLED', false),
        'connection' => env('STORY_RENDER_QUEUE_CONNECTION'),
        'name' => env('STORY_RENDER_QUEUE_NAME', 'default'),
        'timeout' => (int) env('STORY_RENDER_JOB_TIMEOUT', 1200),
        'stale_after_minutes' => (int) env('STORY_RENDER_STALE_MINUTES', 30),
    ],
];
