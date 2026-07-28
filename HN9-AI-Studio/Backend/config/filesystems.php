<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | HN9 AI Studio Domain Disks
        |----------------------------------------------------------------------
        |
        | Dedicated disks for each media/output category in the content supply
        | chain. Private disks hold in-progress source material; the "exports"
        | disk is publicly served for delivery-ready output. Every disk is
        | driver-agnostic and can be repointed to S3 via env without code
        | changes (Repository-ready: consumers depend on the disk name only).
        |
        */

        'projects' => [
            'driver' => env('HN9_DISK_DRIVER', 'local'),
            'root' => storage_path('app/hn9/projects'),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        'images' => [
            'driver' => env('HN9_DISK_DRIVER', 'local'),
            'root' => storage_path('app/hn9/images'),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        'videos' => [
            'driver' => env('HN9_DISK_DRIVER', 'local'),
            'root' => storage_path('app/hn9/videos'),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        'voice' => [
            'driver' => env('HN9_DISK_DRIVER', 'local'),
            'root' => storage_path('app/hn9/voice'),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        'exports' => [
            'driver' => env('HN9_DISK_DRIVER', 'local'),
            'root' => storage_path('app/hn9/exports'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/exports',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'logs' => [
            'driver' => env('HN9_DISK_DRIVER', 'local'),
            'root' => storage_path('app/hn9/logs'),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
        public_path('exports') => storage_path('app/hn9/exports'),
    ],

];
