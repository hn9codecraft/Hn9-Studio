<?php

declare(strict_types=1);

use App\Story\Enums\StoryVideoAsyncMode;
use App\Story\Enums\StoryVideoCapability;

/**
 * Project Story video engine configuration.
 * Provider keys are catalog aliases — not vendor product names in core logic.
 */
return [
    'timeouts' => [
        'connect_timeout_seconds' => (int) env('STORY_VIDEO_CONNECT_TIMEOUT', 10),
        'request_timeout_seconds' => (int) env('STORY_VIDEO_REQUEST_TIMEOUT', 60),
        'total_generation_deadline_seconds' => (int) env('STORY_VIDEO_DEADLINE', 900),
        'max_attempts' => (int) env('STORY_VIDEO_MAX_ATTEMPTS', 3),
        'backoff_ms' => (int) env('STORY_VIDEO_BACKOFF_MS', 500),
        'jitter_ms' => (int) env('STORY_VIDEO_JITTER_MS', 100),
    ],

    /*
    | Catalog adapters demonstrate multi-provider registration without vendor
    | coupling. They never open network sockets. Real providers arrive in M11.7.
    */
    'providers' => [
        [
            'key' => 'catalog.alpha',
            'label' => 'Catalog Provider A',
            'enabled' => true,
            'available' => true,
            'priority' => 100,
            'capabilities' => [
                StoryVideoCapability::TextToVideo->value,
                StoryVideoCapability::ImageToVideo->value,
                StoryVideoCapability::Audio->value,
            ],
            'durations' => [5, 10, 15, 20, 30],
            'min_duration' => 5,
            'max_duration' => 30,
            'aspect_ratios' => ['16:9', '9:16', '1:1'],
            'resolutions' => ['720p', '1080p'],
            'input_types' => ['text', 'image', 'audio'],
            'audio' => true,
            'async_mode' => StoryVideoAsyncMode::AsyncPoll->value,
            'polling' => true,
            'webhook' => false,
            'download' => true,
            'models' => [
                [
                    'key' => 'catalog-alpha-default',
                    'label' => 'Catalog A Default',
                    'enabled' => true,
                    'priority' => 100,
                    'capabilities' => [
                        StoryVideoCapability::TextToVideo->value,
                        StoryVideoCapability::ImageToVideo->value,
                    ],
                    'durations' => [5, 10, 15, 20, 30],
                    'aspect_ratios' => ['16:9', '9:16'],
                    'resolutions' => ['720p', '1080p'],
                    'input_types' => ['text', 'image'],
                    'audio' => true,
                ],
            ],
        ],
        [
            'key' => 'catalog.beta',
            'label' => 'Catalog Provider B',
            'enabled' => true,
            'available' => true,
            'priority' => 90,
            'capabilities' => [
                StoryVideoCapability::ReferenceToVideo->value,
                StoryVideoCapability::VideoEdit->value,
                StoryVideoCapability::TextToVideo->value,
            ],
            'durations' => [8, 16, 24],
            'min_duration' => 8,
            'max_duration' => 24,
            'aspect_ratios' => ['16:9', '9:16'],
            'resolutions' => ['720p'],
            'input_types' => ['text', 'reference_image', 'video'],
            'audio' => false,
            'async_mode' => StoryVideoAsyncMode::AsyncWebhook->value,
            'polling' => false,
            'webhook' => true,
            'download' => true,
            'models' => [
                [
                    'key' => 'catalog-beta-default',
                    'label' => 'Catalog B Default',
                    'enabled' => true,
                    'priority' => 90,
                    'capabilities' => [
                        StoryVideoCapability::ReferenceToVideo->value,
                        StoryVideoCapability::VideoEdit->value,
                        StoryVideoCapability::TextToVideo->value,
                    ],
                    'durations' => [8, 16, 24],
                    'aspect_ratios' => ['16:9', '9:16'],
                    'resolutions' => ['720p'],
                    'input_types' => ['text', 'reference_image', 'video'],
                    'audio' => false,
                ],
            ],
        ],
        [
            'key' => 'catalog.gamma',
            'label' => 'Catalog Provider C',
            'enabled' => true,
            'available' => true,
            'priority' => 80,
            'capabilities' => [
                StoryVideoCapability::VideoExtend->value,
                StoryVideoCapability::Audio->value,
            ],
            'durations' => [10, 20, 30],
            'min_duration' => 10,
            'max_duration' => 30,
            'aspect_ratios' => ['16:9'],
            'resolutions' => ['720p', '1080p'],
            'input_types' => ['video', 'audio', 'text'],
            'audio' => true,
            'async_mode' => StoryVideoAsyncMode::Sync->value,
            'polling' => false,
            'webhook' => false,
            'download' => true,
            'models' => [
                [
                    'key' => 'catalog-gamma-default',
                    'label' => 'Catalog C Default',
                    'enabled' => true,
                    'priority' => 80,
                    'capabilities' => [
                        StoryVideoCapability::VideoExtend->value,
                        StoryVideoCapability::Audio->value,
                    ],
                    'durations' => [10, 20, 30],
                    'aspect_ratios' => ['16:9'],
                    'resolutions' => ['720p', '1080p'],
                    'input_types' => ['video', 'audio', 'text'],
                    'audio' => true,
                ],
            ],
        ],
    ],
];
