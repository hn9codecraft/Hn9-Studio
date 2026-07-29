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
        'claude' => [
            'enabled' => (bool) env('CLAUDE_ENABLED', false),
            'api_key' => env('CLAUDE_API_KEY'),
            'base_url' => env('CLAUDE_BASE_URL'),
            'version' => env('CLAUDE_API_VERSION'),
            'default_model' => env('CLAUDE_DEFAULT_MODEL'),
            'timeout' => (int) env('CLAUDE_TIMEOUT', 30),
            'max_retries' => (int) env('CLAUDE_MAX_RETRIES', 2),
            'models' => array_values(array_filter(explode(',', (string) env('CLAUDE_MODELS', '')))),
            'supports_streaming' => (bool) env('CLAUDE_SUPPORTS_STREAMING', true),
            'supports_function_calling' => (bool) env('CLAUDE_SUPPORTS_FUNCTION_CALLING', true),
            'pricing' => [],
            'options' => [],
        ],
        'openai' => [
            'enabled' => (bool) env('OPENAI_ENABLED', false),
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'default_model' => env('OPENAI_DEFAULT_MODEL'),
            'timeout' => (int) env('OPENAI_TIMEOUT', 30),
            'max_retries' => (int) env('OPENAI_MAX_RETRIES', 2),
            // Comma-separated to keep the environment representation portable.
            'models' => array_values(array_filter(explode(',', (string) env('OPENAI_MODELS', '')))),
            'supports_streaming' => (bool) env('OPENAI_SUPPORTS_STREAMING', true),
            'supports_function_calling' => (bool) env('OPENAI_SUPPORTS_FUNCTION_CALLING', true),
            // Per-million-token USD prices, keyed by configured model identifier.
            'pricing' => [],
            'options' => [],
        ],
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
