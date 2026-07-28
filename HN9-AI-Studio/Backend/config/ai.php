<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | HN9 AI Studio — AI Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the AI provider abstraction (App\AI). This sprint
    | (5.3.1) ships the foundation only: no provider is registered or
    | configured yet. Concrete providers and their credentials are wired in
    | later sprints; the resolver reads the per-provider blocks below.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | The provider key resolved by ProviderManager::default(). Left null until
    | a provider is registered. May also be set at runtime via the registry.
    |
    */

    'default' => env('AI_DEFAULT_PROVIDER'),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Per-provider configuration blocks, keyed by provider key. Resolved into a
    | ProviderConfigDTO by ProviderConfigResolver. Intentionally empty for now.
    |
    | Shape (for later sprints):
    | 'openai' => [
    |     'base_url'      => env('OPENAI_BASE_URL'),
    |     'default_model' => env('OPENAI_DEFAULT_MODEL'),
    |     'timeout'       => 30,
    |     'max_retries'   => 2,
    |     'options'       => [],
    | ],
    |
    */

    'providers' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Health
    |--------------------------------------------------------------------------
    |
    | Tunables for the provider health subsystem.
    |
    */

    'health' => [
        'cache_ttl' => (int) env('AI_HEALTH_CACHE_TTL', 60),
    ],

];
