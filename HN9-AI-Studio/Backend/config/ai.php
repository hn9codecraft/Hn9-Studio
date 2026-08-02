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
    | ProviderConfigDTO by ProviderConfigResolver, then into the provider's own
    | typed settings object. Every block is environment-backed: credentials,
    | endpoints, API versions, timeouts, retries, model lists and prices are
    | supplied at deploy time, never hardcoded in the adapters.
    |
    | A provider is only registered when its `enabled` flag is true.
    |
    */

    'providers' => [
        'elevenlabs' => [
            'enabled' => (bool) env('ELEVENLABS_ENABLED', false),
            'api_key' => env('ELEVENLABS_API_KEY'),
            'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io/v1'),
            'default_model' => env('ELEVENLABS_DEFAULT_MODEL'),
            'timeout' => (int) env('ELEVENLABS_TIMEOUT', 30),
            'max_retries' => (int) env('ELEVENLABS_MAX_RETRIES', 2),
            // Comma-separated text-to-speech model identifiers; none is hardcoded.
            'models' => array_values(array_filter(explode(',', (string) env('ELEVENLABS_MODELS', '')))),
            /*
             * Voices as `name:voice_id` pairs, e.g. "rachel:21m00…,adam:pNInz…".
             * Stock, custom and future voices are all adopted here; the adapter
             * names none. A request may ask for either the name or the id.
             */
            'voices' => env('ELEVENLABS_VOICES', ''),
            'default_voice' => env('ELEVENLABS_DEFAULT_VOICE'),
            // e.g. mp3_44100_128, pcm_16000, ulaw_8000.
            'output_format' => env('ELEVENLABS_OUTPUT_FORMAT'),
            // Optional allow-list; empty means any requested format is accepted.
            'output_formats' => array_values(array_filter(explode(',', (string) env('ELEVENLABS_OUTPUT_FORMATS', '')))),
            /*
             * Default synthesis settings, overridable per request. Accepts the
             * vendor spellings and the studio aliases:
             *   stability, similarity (similarity_boost), style,
             *   speaker_boost (use_speaker_boost), speed.
             */
            'voice_settings' => [],
            // Credits billed per character, keyed by model (standard models: 1.0).
            'credit_multipliers' => [],
            'supports_streaming' => (bool) env('ELEVENLABS_SUPPORTS_STREAMING', true),
            // Per-million-credit USD prices, keyed by configured model identifier.
            'pricing' => [],
            'priority' => (int) env('ELEVENLABS_PRIORITY', 60),
            'options' => [],
        ],
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
        'gemini' => [
            'enabled' => (bool) env('GEMINI_ENABLED', false),
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            'version' => env('GEMINI_API_VERSION', 'v1beta'),
            'default_model' => env('GEMINI_DEFAULT_MODEL'),
            'timeout' => (int) env('GEMINI_TIMEOUT', 30),
            'max_retries' => (int) env('GEMINI_MAX_RETRIES', 2),
            // Comma-separated; no model identifier is hardcoded anywhere in the adapter.
            'models' => array_values(array_filter(explode(',', (string) env('GEMINI_MODELS', '')))),
            // Models permitted to return image output through generateContent.
            'image_models' => array_values(array_filter(explode(',', (string) env('GEMINI_IMAGE_MODELS', '')))),
            'image_default_model' => env('GEMINI_IMAGE_DEFAULT_MODEL'),
            'image_response_modalities' => array_values(array_filter(explode(',', (string) env('GEMINI_IMAGE_RESPONSE_MODALITIES', 'IMAGE')))),
            // Gemini publishes a tokenizer endpoint; disable to count locally only.
            'remote_token_counting' => (bool) env('GEMINI_REMOTE_TOKEN_COUNTING', true),
            'supports_streaming' => (bool) env('GEMINI_SUPPORTS_STREAMING', true),
            'supports_function_calling' => (bool) env('GEMINI_SUPPORTS_FUNCTION_CALLING', true),
            // Per-million-token USD prices, keyed by configured model identifier.
            'pricing' => [],
            'priority' => (int) env('GEMINI_PRIORITY', 80),
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
        'openrouter' => [
            'enabled' => (bool) env('OPENROUTER_ENABLED', false),
            'api_key' => env('OPENROUTER_API_KEY'),
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'default_model' => env('OPENROUTER_DEFAULT_MODEL'),
            'timeout' => (int) env('OPENROUTER_TIMEOUT', 30),
            'max_retries' => (int) env('OPENROUTER_MAX_RETRIES', 2),
            /*
             * OpenRouter aggregates many upstream vendors behind one endpoint, so
             * its catalogue is broad and moves constantly. Models are therefore
             * supplied entirely here — comma-separated, namespaced `vendor/model`
             * identifiers — and no identifier is hardcoded in the adapter:
             * e.g. openai/…, anthropic/…, google/…, deepseek/…, meta-llama/…,
             * mistralai/…, qwen/… and whatever is published next.
             */
            'models' => array_values(array_filter(explode(',', (string) env('OPENROUTER_MODELS', '')))),
            // Optional attribution headers identifying this app to OpenRouter.
            'http_referer' => env('OPENROUTER_HTTP_REFERER'),
            'app_name' => env('OPENROUTER_APP_NAME'),
            // Additional headers sent with every request, applied last.
            'headers' => [],
            // Asks OpenRouter to report the settled cost of each call.
            'usage_accounting' => (bool) env('OPENROUTER_USAGE_ACCOUNTING', true),
            'supports_streaming' => (bool) env('OPENROUTER_SUPPORTS_STREAMING', true),
            'supports_function_calling' => (bool) env('OPENROUTER_SUPPORTS_FUNCTION_CALLING', true),
            /*
             * Per-model metadata, keyed by the identifiers listed above. Every
             * key is optional and nothing is defaulted from a built-in table:
             *
             *   'vendor/model' => [
             *       'provider' => 'vendor',        // defaults to the identifier namespace
             *       'capabilities' => ['text'],
             *       'streaming' => true,
             *       'function_calling' => true,
             *       'context_window' => 128000,
             *       'max_output_tokens' => 16384,
             *   ],
             */
            'model_metadata' => [],
            // Per-million-token USD prices, keyed by configured model identifier.
            'pricing' => [],
            'priority' => (int) env('OPENROUTER_PRIORITY', 70),
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
