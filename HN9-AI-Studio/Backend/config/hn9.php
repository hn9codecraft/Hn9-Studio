<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | HN9 AI Studio — Application Configuration
    |--------------------------------------------------------------------------
    |
    | Domain-level configuration for the HN9 AI Studio backend. Keeping this
    | in a dedicated file (rather than scattering env() calls through the
    | codebase) keeps the application Service- and Repository-ready: services
    | read typed config, never the environment directly.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | API Versioning
    |--------------------------------------------------------------------------
    |
    | The default API version and the full list of supported versions. Routes
    | are grouped under /api/{version}. Adding a new version is additive and
    | never breaks an existing one.
    |
    */

    'api' => [
        'current_version' => env('HN9_API_VERSION', 'v1'),
        'supported_versions' => ['v1'],
        'prefix' => 'api',
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Disks
    |--------------------------------------------------------------------------
    |
    | Logical disk names for each media/output category, mapped to the disks
    | declared in config/filesystems.php. Consumers resolve disks through this
    | map so the physical disk can be repointed without touching call sites.
    |
    */

    'disks' => [
        'projects' => 'projects',
        'images' => 'images',
        'videos' => 'videos',
        'voice' => 'voice',
        'exports' => 'exports',
        'logs' => 'logs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | The log channel used for HN9 domain/operational events (pipeline runs,
    | agent executions, publishing). Distinct from the framework log channel.
    |
    */

    'log_channel' => env('HN9_LOG_CHANNEL', 'hn9'),

    /*
    |--------------------------------------------------------------------------
    | Localization
    |--------------------------------------------------------------------------
    |
    | Content languages supported by the studio. Mirrors the Prompt Engine
    | language layer (en / hi / gu). Used for validation of runtime inputs.
    |
    */

    'locales' => ['en', 'hi', 'gu'],

];
