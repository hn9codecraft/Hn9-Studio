<?php

declare(strict_types=1);

namespace App\Providers;

use App\AI\Cache\CachedHealthManager;
use App\AI\Cache\ProviderInstanceCache;
use App\AI\Cache\ProviderMetadataCache;
use App\AI\Config\PlatformConfig;
use App\AI\Contracts\AIProviderInterface;
use App\AI\Contracts\CircuitBreakerInterface;
use App\AI\Contracts\HealthManagerInterface;
use App\AI\Contracts\HealthTrackerInterface;
use App\AI\Contracts\MetricsCollectorInterface;
use App\AI\Contracts\ProviderDispatcherInterface;
use App\AI\Contracts\ProviderFactoryInterface;
use App\AI\Contracts\ProviderManagerInterface;
use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\Contracts\ProviderRouterInterface;
use App\AI\Contracts\RetryPolicyInterface;
use App\AI\DTOs\ProviderCapabilityDTO;
use App\AI\DTOs\ProviderConfigDTO;
use App\AI\Execution\ModalityInvoker;
use App\AI\Execution\ModalityInvokerRegistry;
use App\AI\Execution\ProviderDispatcher;
use App\AI\Factory\ProviderFactory;
use App\AI\Health\HealthManager;
use App\AI\Health\ProviderHealthTracker;
use App\AI\Manager\ProviderManager;
use App\AI\Metrics\CacheMetricsCollector;
use App\AI\Metrics\NullMetricsCollector;
use App\AI\Providers\Claude\ClaudeClient;
use App\AI\Providers\Claude\ClaudeConfig;
use App\AI\Providers\Claude\ClaudeModelRegistry;
use App\AI\Providers\Claude\ClaudeProvider;
use App\AI\Providers\Claude\ClaudeResponseNormalizer;
use App\AI\Providers\Claude\ClaudeTokenCounter;
use App\AI\Providers\Claude\ClaudeUsageCalculator;
use App\AI\Providers\ElevenLabs\ElevenLabsClient;
use App\AI\Providers\ElevenLabs\ElevenLabsConfig;
use App\AI\Providers\ElevenLabs\ElevenLabsProvider;
use App\AI\Providers\ElevenLabs\ElevenLabsResponseNormalizer;
use App\AI\Providers\ElevenLabs\ElevenLabsTokenCounter;
use App\AI\Providers\ElevenLabs\ElevenLabsUsageCalculator;
use App\AI\Providers\ElevenLabs\ElevenLabsVoiceRegistry;
use App\AI\Providers\Gemini\GeminiClient;
use App\AI\Providers\Gemini\GeminiConfig;
use App\AI\Providers\Gemini\GeminiModelRegistry;
use App\AI\Providers\Gemini\GeminiProvider;
use App\AI\Providers\Gemini\GeminiResponseNormalizer;
use App\AI\Providers\Gemini\GeminiTokenCounter;
use App\AI\Providers\Gemini\GeminiUsageCalculator;
use App\AI\Providers\OpenAI\OpenAIClient;
use App\AI\Providers\OpenAI\OpenAIConfig;
use App\AI\Providers\OpenAI\OpenAIModelRegistry;
use App\AI\Providers\OpenAI\OpenAIProvider;
use App\AI\Providers\OpenAI\OpenAIResponseNormalizer;
use App\AI\Providers\OpenAI\OpenAITokenCounter;
use App\AI\Providers\OpenAI\OpenAIUsageCalculator;
use App\AI\Providers\OpenRouter\OpenRouterClient;
use App\AI\Providers\OpenRouter\OpenRouterConfig;
use App\AI\Providers\OpenRouter\OpenRouterModelRegistry;
use App\AI\Providers\OpenRouter\OpenRouterProvider;
use App\AI\Providers\OpenRouter\OpenRouterResponseNormalizer;
use App\AI\Providers\OpenRouter\OpenRouterTokenCounter;
use App\AI\Providers\OpenRouter\OpenRouterUsageCalculator;
use App\AI\Registry\ProviderRegistry;
use App\AI\Requests\ImageRequest;
use App\AI\Requests\TextRequest;
use App\AI\Requests\VideoRequest;
use App\AI\Requests\VoiceRequest;
use App\AI\Resilience\CircuitBreaker;
use App\AI\Resilience\Retrier;
use App\AI\Resilience\RetryPolicy;
use App\AI\Responses\ImageResponse;
use App\AI\Responses\TextResponse;
use App\AI\Responses\VideoResponse;
use App\AI\Responses\VoiceResponse;
use App\AI\Routing\CostEstimator;
use App\AI\Routing\ProviderRouter;
use App\AI\Routing\RoutingStrategyRegistry;
use App\AI\Routing\Strategies\BalancedStrategy;
use App\AI\Routing\Strategies\CheapestStrategy;
use App\AI\Routing\Strategies\FastestStrategy;
use App\AI\Routing\Strategies\PriorityStrategy;
use App\AI\Routing\Strategies\QualityStrategy;
use App\AI\Support\Modality;
use App\AI\Support\ProviderConfigResolver;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the AI provider foundation (App\AI) into the container.
 *
 * The registry is a singleton so provider registrations persist for the
 * request/worker lifetime; the factory, health manager and manager are bound to
 * their contracts and resolved on demand. Every dependency is an interface, so
 * concrete providers plug in here — and only here — without the manager,
 * factory or registry gaining provider-specific knowledge.
 *
 * Sprint 5.3.7 adds the intelligence layer on top of that foundation: parsed
 * configuration, the provider and metadata caches, passive health, the circuit
 * breaker, the retry policy, metrics, the routing strategies and the resilient
 * dispatcher. All of it is additive — the manager, factory and registry
 * bindings behave exactly as before.
 */
class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The registry holds process-lifetime registrations → singleton.
        $this->app->singleton(ProviderRegistryInterface::class, ProviderRegistry::class);

        $this->app->bind(ProviderFactoryInterface::class, ProviderFactory::class);
        $this->app->bind(ProviderManagerInterface::class, ProviderManager::class);

        $this->registerPlatformConfig();
        $this->registerCaches();
        $this->registerHealth();
        $this->registerResilience();
        $this->registerMetrics();
        $this->registerRouting();
        $this->registerExecution();
    }

    /**
     * `config/ai.php` parsed once per process. Every component below reads its
     * settings from this object rather than from the configuration repository,
     * so the coercion happens once instead of on every dispatch.
     */
    private function registerPlatformConfig(): void
    {
        $this->app->singleton(PlatformConfig::class, static function (Application $app): PlatformConfig {
            /** @var array<string, mixed> $config */
            $config = $app->make('config')->get('ai', []);

            return PlatformConfig::fromArray($config);
        });
    }

    /**
     * Provider instances and their metadata are memoised for the process:
     * building a provider allocates a client, a model registry, a usage
     * calculator, a normaliser and a token counter, and routing may touch
     * several providers per request.
     */
    private function registerCaches(): void
    {
        $this->app->singleton(ProviderInstanceCache::class, fn (Application $app): ProviderInstanceCache => new ProviderInstanceCache(
            $app->make(ProviderFactoryInterface::class),
            $app->make(ProviderRegistryInterface::class),
            $app->make(PlatformConfig::class)->cache->providerInstances,
        ));

        $this->app->singleton(ProviderMetadataCache::class);
    }

    /**
     * Active probes stay with the existing health manager; caching and the
     * passive, outcome-derived view are layered around it.
     */
    private function registerHealth(): void
    {
        $this->app->bind(HealthManagerInterface::class, function (Application $app): HealthManagerInterface {
            $manager = $app->make(HealthManager::class);
            $config = $app->make(PlatformConfig::class);

            if (! (bool) $app->make('config')->get('ai.health.cache_enabled', true)) {
                return $manager;
            }

            return new CachedHealthManager(
                $manager,
                $app->make(ProviderRegistryInterface::class),
                $this->cacheStore($config->cache->store),
                $config->cache,
            );
        });

        $this->app->singleton(
            HealthTrackerInterface::class,
            fn (Application $app): HealthTrackerInterface => new ProviderHealthTracker(
                $this->cacheStore($app->make(PlatformConfig::class)->cache->store),
                $app->make(PlatformConfig::class)->cache,
                $app->make(PlatformConfig::class)->routing->health,
            ),
        );
    }

    private function registerResilience(): void
    {
        $this->app->singleton(
            CircuitBreakerInterface::class,
            fn (Application $app): CircuitBreakerInterface => new CircuitBreaker(
                $this->cacheStore($app->make(PlatformConfig::class)->circuitBreaker->store),
                $app->make(PlatformConfig::class)->circuitBreaker,
            ),
        );

        $this->app->singleton(
            RetryPolicyInterface::class,
            static fn (Application $app): RetryPolicyInterface => new RetryPolicy(
                $app->make(PlatformConfig::class)->retry,
            ),
        );

        $this->app->singleton(Retrier::class);
    }

    /**
     * Disabling metrics swaps in the null collector, so the dispatcher never
     * has to ask whether recording is switched on.
     */
    private function registerMetrics(): void
    {
        $this->app->singleton(MetricsCollectorInterface::class, function (Application $app): MetricsCollectorInterface {
            $config = $app->make(PlatformConfig::class)->metrics;

            if (! $config->enabled) {
                return new NullMetricsCollector;
            }

            return new CacheMetricsCollector($this->cacheStore($config->store), $config);
        });
    }

    /**
     * The strategy registry is the Open/Closed seam for selection policy: an
     * additional strategy is registered here and named in configuration, and
     * the router gains no branch.
     */
    private function registerRouting(): void
    {
        $this->app->singleton(RoutingStrategyRegistry::class, static function (Application $app): RoutingStrategyRegistry {
            $registry = new RoutingStrategyRegistry;
            $routing = $app->make(PlatformConfig::class)->routing;

            $registry->register(new PriorityStrategy);
            $registry->register(new CheapestStrategy);
            $registry->register(new FastestStrategy);
            $registry->register(new QualityStrategy);
            $registry->register(new BalancedStrategy($routing), default: true);

            return $registry;
        });

        $this->app->singleton(CostEstimator::class);
        $this->app->bind(ProviderRouterInterface::class, ProviderRouter::class);
    }

    /**
     * Modality invokers map a request onto the provider method that serves it.
     * Registering one here is how a future modality becomes dispatchable
     * without touching the dispatcher.
     */
    private function registerExecution(): void
    {
        $this->app->singleton(ModalityInvokerRegistry::class, static function (): ModalityInvokerRegistry {
            $registry = new ModalityInvokerRegistry;

            $registry->register(new ModalityInvoker(
                Modality::Text,
                TextRequest::class,
                static fn (AIProviderInterface $provider, TextRequest $request): TextResponse => $provider->generateText($request),
            ));

            $registry->register(new ModalityInvoker(
                Modality::Image,
                ImageRequest::class,
                static fn (AIProviderInterface $provider, ImageRequest $request): ImageResponse => $provider->generateImage($request),
            ));

            $registry->register(new ModalityInvoker(
                Modality::Voice,
                VoiceRequest::class,
                static fn (AIProviderInterface $provider, VoiceRequest $request): VoiceResponse => $provider->generateVoice($request),
            ));

            $registry->register(new ModalityInvoker(
                Modality::Video,
                VideoRequest::class,
                static fn (AIProviderInterface $provider, VideoRequest $request): VideoResponse => $provider->generateVideo($request),
            ));

            return $registry;
        });

        $this->app->bind(ProviderDispatcherInterface::class, ProviderDispatcher::class);
    }

    /**
     * A cache repository by store name; null means the application default.
     */
    private function cacheStore(?string $store): Repository
    {
        /** @var CacheFactory $cache */
        $cache = $this->app->make(CacheFactory::class);

        return $cache->store($store);
    }

    public function boot(): void
    {
        /** @var ProviderConfigResolver $resolver */
        $resolver = $this->app->make(ProviderConfigResolver::class);

        $this->registerClaudeProvider($resolver);
        $this->registerOpenAIProvider($resolver);
        $this->registerGeminiProvider($resolver);
        $this->registerOpenRouterProvider($resolver);
        $this->registerElevenLabsProvider($resolver);
    }

    private function registerElevenLabsProvider(ProviderConfigResolver $resolver): void
    {
        $settings = $this->settingsFor('elevenlabs');

        if ($settings === null) {
            return;
        }

        $config = ElevenLabsConfig::fromProviderConfig($resolver->resolve('elevenlabs'));
        $voices = new ElevenLabsVoiceRegistry($config);

        $this->registry()->register(
            'elevenlabs',
            fn (ProviderConfigDTO $providerConfig): ElevenLabsProvider => $this->makeElevenLabsProvider(
                ElevenLabsConfig::fromProviderConfig($providerConfig),
            ),
            new ProviderCapabilityDTO(
                key: 'elevenlabs', name: 'ElevenLabs', version: ElevenLabsProvider::VERSION,
                // Text-to-speech only; no other modality is declared.
                voice: true, streaming: $config->supportsStreaming, models: $voices->all(),
            ),
            priority: (int) ($settings['priority'] ?? 60),
        );
    }

    private function makeElevenLabsProvider(ElevenLabsConfig $config): ElevenLabsProvider
    {
        $usage = new ElevenLabsUsageCalculator($config);

        return new ElevenLabsProvider(
            new ElevenLabsClient($this->app->make(Factory::class), $config),
            new ElevenLabsVoiceRegistry($config),
            $usage,
            new ElevenLabsResponseNormalizer($usage),
            new ElevenLabsTokenCounter,
            $config,
        );
    }

    private function registerClaudeProvider(ProviderConfigResolver $resolver): void
    {
        $settings = $this->settingsFor('claude');

        if ($settings === null) {
            return;
        }

        $config = ClaudeConfig::fromProviderConfig($resolver->resolve('claude'));
        $models = new ClaudeModelRegistry($config);

        $this->registry()->register(
            'claude',
            fn (ProviderConfigDTO $providerConfig): ClaudeProvider => new ClaudeProvider(
                new ClaudeClient($this->app->make(Factory::class), ClaudeConfig::fromProviderConfig($providerConfig)),
                $models,
                new ClaudeUsageCalculator($config),
                new ClaudeResponseNormalizer(new ClaudeUsageCalculator($config)),
                new ClaudeTokenCounter,
                $config,
            ),
            new ProviderCapabilityDTO('claude', 'Claude', ClaudeProvider::VERSION, text: true, streaming: $config->supportsStreaming, functionCalling: $config->supportsFunctionCalling, models: $models->all()),
            priority: (int) ($settings['priority'] ?? 90),
        );
    }

    private function registerOpenAIProvider(ProviderConfigResolver $resolver): void
    {
        $settings = $this->settingsFor('openai');

        if ($settings === null) {
            return;
        }

        $config = OpenAIConfig::fromProviderConfig($resolver->resolve('openai'));
        $models = new OpenAIModelRegistry($config);

        $this->registry()->register(
            'openai',
            fn (ProviderConfigDTO $providerConfig): OpenAIProvider => new OpenAIProvider(
                new OpenAIClient($this->app->make(Factory::class), OpenAIConfig::fromProviderConfig($providerConfig)),
                $models,
                new OpenAIUsageCalculator($config),
                new OpenAIResponseNormalizer(new OpenAIUsageCalculator($config)),
                new OpenAITokenCounter,
                $config,
            ),
            new ProviderCapabilityDTO(
                key: 'openai', name: 'OpenAI', version: OpenAIProvider::VERSION,
                text: true, image: true, streaming: $config->supportsStreaming,
                functionCalling: $config->supportsFunctionCalling, models: $models->all(),
            ),
            priority: (int) ($settings['priority'] ?? 100),
        );
    }

    private function registerGeminiProvider(ProviderConfigResolver $resolver): void
    {
        $settings = $this->settingsFor('gemini');

        if ($settings === null) {
            return;
        }

        $config = GeminiConfig::fromProviderConfig($resolver->resolve('gemini'));
        $models = new GeminiModelRegistry($config);

        $this->registry()->register(
            'gemini',
            fn (ProviderConfigDTO $providerConfig): GeminiProvider => $this->makeGeminiProvider(
                GeminiConfig::fromProviderConfig($providerConfig),
            ),
            new ProviderCapabilityDTO(
                key: 'gemini', name: 'Gemini', version: GeminiProvider::VERSION,
                // Image support is declared only when image-capable models are configured.
                text: true, image: $config->imageModels !== [], streaming: $config->supportsStreaming,
                functionCalling: $config->supportsFunctionCalling, models: $models->all(),
            ),
            priority: (int) ($settings['priority'] ?? 80),
        );
    }

    private function registerOpenRouterProvider(ProviderConfigResolver $resolver): void
    {
        $settings = $this->settingsFor('openrouter');

        if ($settings === null) {
            return;
        }

        $config = OpenRouterConfig::fromProviderConfig($resolver->resolve('openrouter'));
        $models = new OpenRouterModelRegistry($config);

        $this->registry()->register(
            'openrouter',
            fn (ProviderConfigDTO $providerConfig): OpenRouterProvider => $this->makeOpenRouterProvider(
                OpenRouterConfig::fromProviderConfig($providerConfig),
            ),
            new ProviderCapabilityDTO(
                key: 'openrouter', name: 'OpenRouter', version: OpenRouterProvider::VERSION,
                text: true, streaming: $config->supportsStreaming,
                functionCalling: $config->supportsFunctionCalling, models: $models->all(),
                // Declared only when the default model's window is configured.
                maxInputTokens: $models->defaultContextWindow(),
            ),
            priority: (int) ($settings['priority'] ?? 70),
        );
    }

    private function makeOpenRouterProvider(OpenRouterConfig $config): OpenRouterProvider
    {
        $usage = new OpenRouterUsageCalculator($config);

        return new OpenRouterProvider(
            new OpenRouterClient($this->app->make(Factory::class), $config),
            new OpenRouterModelRegistry($config),
            $usage,
            new OpenRouterResponseNormalizer($usage),
            new OpenRouterTokenCounter,
            $config,
        );
    }

    private function makeGeminiProvider(GeminiConfig $config): GeminiProvider
    {
        $client = new GeminiClient($this->app->make(Factory::class), $config);
        $usage = new GeminiUsageCalculator($config);
        $normalizer = new GeminiResponseNormalizer($usage);

        return new GeminiProvider(
            $client,
            new GeminiModelRegistry($config),
            $usage,
            $normalizer,
            new GeminiTokenCounter($client, $normalizer, $config),
            $config,
        );
    }

    /**
     * The provider's configuration block, or null when it is absent or disabled.
     *
     * @return array<string, mixed>|null
     */
    private function settingsFor(string $key): ?array
    {
        $settings = config("ai.providers.{$key}", []);

        if (! is_array($settings) || ! ($settings['enabled'] ?? false)) {
            return null;
        }

        return $settings;
    }

    private function registry(): ProviderRegistryInterface
    {
        /** @var ProviderRegistryInterface $registry */
        $registry = $this->app->make(ProviderRegistryInterface::class);

        return $registry;
    }
}
