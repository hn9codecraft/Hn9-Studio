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
    | Queued mode runs submit, poll and download on the queue and requires a
    | running worker. When disabled, submit runs in the request and the status
    | endpoint advances accepted jobs.
    */
    'queue' => [
        'enabled' => (bool) env('STORY_VIDEO_QUEUE_ENABLED', false),
        'name' => env('STORY_VIDEO_QUEUE_NAME', 'default'),
    ],

    /*
    | Catalog adapters demonstrate multi-provider registration without vendor
    | coupling. They never open network sockets. Real providers arrive in M11.7.
    */
    /*
    | Null means: enable outside the test suite when a video credential is already configured.
    | Tests opt in explicitly. Do not put secrets in this file.
    */
    'real_provider' => [
        'enabled' => env('STORY_VIDEO_REAL_PROVIDER'),
        'key' => 'video.live',
        'durations' => [8],
    ],

    /*
    | Scene sound. ElevenLabs speaks through the shared AI provider configured in
    | config/ai.php (ELEVENLABS_*); it connects only when that provider is enabled
    | and has a voice. Text-to-speech covers spoken roles only.
    */
    'audio_providers' => [
        'elevenlabs' => [
            'key' => 'audio.elevenlabs',
            'label' => 'ElevenLabs voice',
            'priority' => (int) env('ELEVENLABS_STORY_PRIORITY', 260),
            'roles' => ['voice', 'narration', 'dialogue'],
            'max_characters' => (int) env('ELEVENLABS_STORY_MAX_CHARACTERS', 5000),
        ],
    ],

    /*
    | Credentialed video providers behind the same engine. Each one registers
    | only when its key is present; "enabled" null means: on outside the test
    | suite whenever a key is configured. The highest priority provider that
    | supports a request serves it. Keys come from the environment only.
    */
    /*
    | Unit routing policy. Order is the preference, not a PHP branch.
    | Runway is first unless this list is changed. Fallback may choose the
    | next eligible provider only before a job is submitted.
    */
    'routing' => [
        'policy_version' => 'm11.18.4',
        'preferred_order' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('STORY_VIDEO_PREFERRED_PROVIDERS', 'video.runway,video.luma,video.seedance')),
        ))),
        'fallback_enabled' => filter_var(env('STORY_VIDEO_ROUTING_FALLBACK', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'live_providers' => [
        'seedance' => [
            'enabled' => env('SEEDANCE_ENABLED'),
            'key' => 'video.seedance',
            'label' => 'Seedance 2.0',
            'priority' => (int) env('SEEDANCE_PRIORITY', 300),
            'api_key' => env('ARK_API_KEY'),
            'base_url' => env('SEEDANCE_BASE_URL', 'https://ark.ap-southeast.bytepluses.com/api/v3'),
            'model' => env('SEEDANCE_MODEL', 'dreamina-seedance-2-0-260128'),
            'resolution' => env('SEEDANCE_RESOLUTION', '720p'),
            // Public HTTPS address of this API; enables status callbacks.
            'callback_base_url' => env('SEEDANCE_CALLBACK_BASE_URL'),
            'execution_expires_after' => (int) env('SEEDANCE_TASK_EXPIRES_SECONDS', 172800),
            // Edit/extend need signed URLs from a cloud videos disk.
            'signed_reference_videos' => (bool) env('SEEDANCE_SIGNED_REFERENCE_VIDEOS', false),
            'connect_timeout_seconds' => (int) env('SEEDANCE_CONNECT_TIMEOUT', 10),
            'request_timeout_seconds' => (int) env('SEEDANCE_REQUEST_TIMEOUT', 60),
            'download_timeout_seconds' => (int) env('SEEDANCE_DOWNLOAD_TIMEOUT', 180),
        ],
        'luma' => [
            'enabled' => env('LUMA_ENABLED'),
            'key' => 'video.luma',
            'label' => 'Luma Ray',
            'priority' => (int) env('LUMA_PRIORITY', 250),
            'api_key' => env('LUMA_AGENTS_API_KEY'),
            'base_url' => env('LUMA_BASE_URL', 'https://agents.lumalabs.ai/v1'),
            'model' => env('LUMA_MODEL', 'ray-3.2'),
            'resolution' => env('LUMA_RESOLUTION', '720p'),
            'connect_timeout_seconds' => (int) env('LUMA_CONNECT_TIMEOUT', 10),
            'request_timeout_seconds' => (int) env('LUMA_REQUEST_TIMEOUT', 60),
            'download_timeout_seconds' => (int) env('LUMA_DOWNLOAD_TIMEOUT', 180),
        ],
        'runway' => [
            'enabled' => env('RUNWAY_ENABLED'),
            'key' => 'video.runway',
            'label' => 'Runway',
            'priority' => (int) env('RUNWAY_PRIORITY', 240),
            'api_key' => env('RUNWAYML_API_SECRET'),
            'base_url' => env('RUNWAY_BASE_URL', 'https://api.dev.runwayml.com'),
            'api_version' => env('RUNWAY_API_VERSION', '2024-11-06'),
            'model' => env('RUNWAY_MODEL', 'gen4.5'),
            'edit_model' => env('RUNWAY_EDIT_MODEL', 'aleph2'),
            'connect_timeout_seconds' => (int) env('RUNWAY_CONNECT_TIMEOUT', 10),
            'request_timeout_seconds' => (int) env('RUNWAY_REQUEST_TIMEOUT', 60),
            'download_timeout_seconds' => (int) env('RUNWAY_DOWNLOAD_TIMEOUT', 180),
        ],
    ],

    /*
    | Local media toolkit for joining scene parts and building the final video.
    | When the binaries are missing the Studio says so instead of producing a file.
    */
    'ffmpeg' => [
        'binary' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
        'timeout_seconds' => (int) env('FFMPEG_TIMEOUT', 900),
        'width' => (int) env('STORY_RENDER_WIDTH', 1280),
        'height' => (int) env('STORY_RENDER_HEIGHT', 720),
        'fps' => (int) env('STORY_RENDER_FPS', 24),
    ],

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
            'audio_roles' => ['voice', 'narration', 'dialogue', 'generated'],
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
            'audio_roles' => [
                'voice',
                'narration',
                'dialogue',
                'music',
                'sfx',
                'ambient',
                'generated',
            ],
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
