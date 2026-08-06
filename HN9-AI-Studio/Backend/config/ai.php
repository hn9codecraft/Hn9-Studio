<?php

declare(strict_types=1);

use App\AI\Exceptions\AIException;
use App\AI\Exceptions\ProviderApiException;
use App\AI\Exceptions\ProviderAuthenticationException;
use App\AI\Exceptions\ProviderDisabledException;
use App\AI\Exceptions\ProviderNetworkException;
use App\AI\Exceptions\ProviderNotConfiguredException;
use App\AI\Exceptions\ProviderNotRegisteredException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Exceptions\UnsupportedCapabilityException;

/**
 * A comma-separated environment variable read as a list of trimmed values.
 *
 * @return list<string>
 */
$list = static fn (string $variable, string $default = ''): array => array_values(array_filter(
    array_map(trim(...), explode(',', (string) env($variable, $default))),
    static fn (string $item): bool => $item !== '',
));

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
            'models' => $list('ELEVENLABS_MODELS'),
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
            'output_formats' => $list('ELEVENLABS_OUTPUT_FORMATS'),
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
            'models' => $list('CLAUDE_MODELS'),
            'supports_streaming' => (bool) env('CLAUDE_SUPPORTS_STREAMING', true),
            'supports_function_calling' => (bool) env('CLAUDE_SUPPORTS_FUNCTION_CALLING', true),
            'pricing' => [],
            'priority' => (int) env('CLAUDE_PRIORITY', 90),
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
            'models' => $list('GEMINI_MODELS'),
            // Models permitted to return image output through generateContent.
            'image_models' => $list('GEMINI_IMAGE_MODELS'),
            'image_default_model' => env('GEMINI_IMAGE_DEFAULT_MODEL'),
            'image_response_modalities' => $list('GEMINI_IMAGE_RESPONSE_MODALITIES', 'IMAGE'),
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
            'models' => $list('OPENAI_MODELS'),
            'supports_streaming' => (bool) env('OPENAI_SUPPORTS_STREAMING', true),
            'supports_function_calling' => (bool) env('OPENAI_SUPPORTS_FUNCTION_CALLING', true),
            // Per-million-token USD prices, keyed by configured model identifier.
            'pricing' => [],
            'priority' => (int) env('OPENAI_PRIORITY', 100),
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
            'models' => $list('OPENROUTER_MODELS'),
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
        // Memoises probe results for `cache_ttl` seconds so routing never
        // re-probes a vendor on every request.
        'cache_enabled' => (bool) env('AI_HEALTH_CACHE_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    |
    | How the platform picks a provider for a capability. Selection is scored,
    | never hardcoded: a strategy ranks the candidates that survive capability,
    | configuration, health, circuit and budget filtering. No provider key
    | appears anywhere in the routing code — every key below is supplied here.
    |
    */

    'routing' => [
        // Registered strategy key: priority | cheapest | fastest | quality | balanced.
        'strategy' => (string) env('AI_ROUTING_STRATEGY', 'balanced'),

        /*
         * Operator-level provider preference, highest first. A preferred
         * provider is promoted ahead of the scored order when it is a viable
         * candidate, and silently ignored when it is not.
         */
        'preferred' => $list('AI_PREFERRED_PROVIDERS'),

        /*
         * Relative importance of each signal for the `balanced` strategy. Every
         * signal is normalised to 0..1 across the candidate set before weighting,
         * so the numbers below are pure ratios.
         */
        'weights' => [
            'priority' => (float) env('AI_ROUTING_WEIGHT_PRIORITY', 0.40),
            'cost' => (float) env('AI_ROUTING_WEIGHT_COST', 0.25),
            'latency' => (float) env('AI_ROUTING_WEIGHT_LATENCY', 0.20),
            'reliability' => (float) env('AI_ROUTING_WEIGHT_RELIABILITY', 0.15),
        ],

        'health' => [
            'enabled' => (bool) env('AI_ROUTING_HEALTH_ENABLED', true),
            /*
             * Passive health (observed outcomes) is always free. Setting this
             * true additionally consults the cached health probes, which issue
             * real vendor requests — off by default.
             */
            'probe' => (bool) env('AI_ROUTING_HEALTH_PROBE', false),
            'exclude_unavailable' => (bool) env('AI_ROUTING_EXCLUDE_UNAVAILABLE', true),
            // A never-exercised provider reports Unknown; it stays routable.
            'exclude_unknown' => (bool) env('AI_ROUTING_EXCLUDE_UNKNOWN', false),
            /*
             * Ranks a degraded provider behind its healthy peers instead of
             * removing it: it still serves when nothing healthier is available.
             */
            'demote_degraded' => (bool) env('AI_ROUTING_DEMOTE_DEGRADED', true),
            // Consecutive failures before a provider is degraded, then unavailable.
            'degraded_threshold' => (int) env('AI_HEALTH_DEGRADED_THRESHOLD', 1),
            'unavailable_threshold' => (int) env('AI_HEALTH_UNAVAILABLE_THRESHOLD', 3),
            /*
             * Observed state is held for this long. Expiry is the automatic
             * recovery path: an idle provider returns to Unknown and is routable
             * again without operator action.
             */
            'recovery_seconds' => (int) env('AI_HEALTH_RECOVERY_SECONDS', 300),
        ],

        'fallback' => [
            'enabled' => (bool) env('AI_FALLBACK_ENABLED', true),
            // Upper bound on providers tried for one request.
            'max_providers' => (int) env('AI_FALLBACK_MAX_PROVIDERS', 3),
            /*
             * How a configured chain interacts with scoring:
             *   order    — the chain dictates the order; unlisted providers follow, scored.
             *   restrict — the chain only limits the candidate set; scoring orders it.
             */
            'mode' => (string) env('AI_FALLBACK_CHAIN_MODE', 'order'),
            /*
             * Ordered provider keys per capability, e.g.
             *   AI_FALLBACK_CHAIN_TEXT="openai,claude,gemini,openrouter"
             * Empty means "no chain" — the strategy alone decides.
             */
            'chains' => [
                'text' => $list('AI_FALLBACK_CHAIN_TEXT'),
                'image' => $list('AI_FALLBACK_CHAIN_IMAGE'),
                'voice' => $list('AI_FALLBACK_CHAIN_VOICE'),
                'video' => $list('AI_FALLBACK_CHAIN_VIDEO'),
            ],
            // Only these failures move the request on to the next provider.
            'exceptions' => [
                AIException::class,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry
    |--------------------------------------------------------------------------
    |
    | Orchestration-level retry, applied per provider attempt. Distinct from the
    | transport retry inside AbstractProviderClient, which only re-sends a
    | connection-level blip: this policy reasons about typed failures and hands
    | control to the fallback chain once a provider is exhausted.
    |
    */

    'retry' => [
        'enabled' => (bool) env('AI_RETRY_ENABLED', true),
        // Total attempts per provider, including the first.
        'max_attempts' => (int) env('AI_RETRY_MAX_ATTEMPTS', 3),
        'delay_ms' => (int) env('AI_RETRY_DELAY_MS', 200),
        // Exponential backoff: delay * multiplier^(attempt - 1), capped.
        'multiplier' => (float) env('AI_RETRY_MULTIPLIER', 2.0),
        'max_delay_ms' => (int) env('AI_RETRY_MAX_DELAY_MS', 5_000),
        // Spreads retries of concurrent callers to avoid a synchronised stampede.
        'jitter' => (bool) env('AI_RETRY_JITTER', true),
        'jitter_ratio' => (float) env('AI_RETRY_JITTER_RATIO', 0.25),

        // Transient failures worth repeating against the same provider.
        'retryable' => [
            ProviderTimeoutException::class,
            ProviderNetworkException::class,
            ProviderRateLimitException::class,
            ProviderApiException::class,
        ],
        // Deterministic failures: repeating them only wastes the deadline.
        'non_retryable' => [
            ProviderAuthenticationException::class,
            UnsupportedCapabilityException::class,
            ProviderNotRegisteredException::class,
            ProviderNotConfiguredException::class,
            ProviderDisabledException::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | Per-provider closed → open → half-open state machine. An open circuit is
    | removed from routing until the recovery timeout elapses, after which a
    | half-open probe decides whether the provider is restored or re-opened.
    |
    */

    'circuit_breaker' => [
        'enabled' => (bool) env('AI_CIRCUIT_BREAKER_ENABLED', true),
        // Consecutive failures that open a closed circuit.
        'failure_threshold' => (int) env('AI_CIRCUIT_FAILURE_THRESHOLD', 5),
        // Consecutive half-open successes that close it again.
        'success_threshold' => (int) env('AI_CIRCUIT_SUCCESS_THRESHOLD', 2),
        // Seconds an open circuit waits before allowing a half-open probe.
        'recovery_timeout' => (int) env('AI_CIRCUIT_RECOVERY_TIMEOUT', 60),
        // Cache store holding circuit state; null uses the application default.
        'store' => env('AI_CIRCUIT_STORE'),
        'prefix' => (string) env('AI_CIRCUIT_PREFIX', 'ai:circuit'),
        // Failures that indicate the provider itself is unwell.
        'trip_on' => [
            ProviderTimeoutException::class,
            ProviderNetworkException::class,
            ProviderRateLimitException::class,
            ProviderApiException::class,
            ProviderAuthenticationException::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cost
    |--------------------------------------------------------------------------
    |
    | Budget and cost-preference inputs to routing. Estimation calls each
    | candidate's estimateCost(); one provider's estimator consults a remote
    | tokenizer, so this is opt-in rather than on by default.
    |
    */

    'cost' => [
        'enabled' => (bool) env('AI_COST_OPTIMIZATION_ENABLED', false),
        // cheapest | balanced | quality
        'strategy' => (string) env('AI_COST_STRATEGY', 'balanced'),
        // Hard ceiling per request in `currency`; null disables budget filtering.
        'budget' => env('AI_COST_BUDGET') === null ? null : (float) env('AI_COST_BUDGET'),
        'currency' => (string) env('AI_COST_CURRENCY', 'USD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Counters describing platform behaviour: throughput, outcome, latency,
    | retries, fallbacks and estimated spend, per provider and per capability.
    |
    */

    'metrics' => [
        'enabled' => (bool) env('AI_METRICS_ENABLED', true),
        'store' => env('AI_METRICS_STORE'),
        'prefix' => (string) env('AI_METRICS_PREFIX', 'ai:metrics'),
        // Rolling retention window, in seconds.
        'ttl' => (int) env('AI_METRICS_TTL', 86_400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Caches that keep the hot path allocation-free: built provider instances and
    | their metadata are memoised for the process, health and cost estimates are
    | shared through the cache store.
    |
    */

    'cache' => [
        'store' => env('AI_CACHE_STORE'),
        'prefix' => (string) env('AI_CACHE_PREFIX', 'ai'),
        // Memoise built providers for the request/worker lifetime.
        'providers' => (bool) env('AI_PROVIDER_INSTANCE_CACHE', true),
        'ttl' => [
            'metadata' => (int) env('AI_METADATA_CACHE_TTL', 600),
            'cost' => (int) env('AI_COST_CACHE_TTL', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | `connect` and `request` are transport defaults applied by every provider
    | client; a provider block's own `timeout` still wins. `total_ms` is the
    | end-to-end budget for one dispatch — retries and fallbacks included — after
    | which the platform fails gracefully rather than queueing more attempts.
    |
    */

    'timeouts' => [
        'connect' => (int) env('AI_CONNECT_TIMEOUT', 10),
        'request' => (int) env('AI_REQUEST_TIMEOUT', 30),
        'total_ms' => (int) env('AI_TOTAL_TIMEOUT_MS', 120_000),
    ],

];
