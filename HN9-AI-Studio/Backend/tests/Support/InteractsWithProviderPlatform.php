<?php

declare(strict_types=1);

namespace Tests\Support;

use App\AI\Cache\ProviderInstanceCache;
use App\AI\Cache\ProviderMetadataCache;
use App\AI\Config\PlatformConfig;
use App\AI\Contracts\AIProviderInterface;
use App\AI\Contracts\CircuitBreakerInterface;
use App\AI\Contracts\HealthTrackerInterface;
use App\AI\Contracts\MetricsCollectorInterface;
use App\AI\Contracts\ProviderDispatcherInterface;
use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\Contracts\ProviderRouterInterface;
use App\AI\Contracts\RetryPolicyInterface;
use App\AI\DTOs\ProviderCapabilityDTO;
use App\AI\DTOs\ProviderConfigDTO;
use App\AI\Execution\ModalityInvokerRegistry;
use App\AI\Routing\CostEstimator;
use App\AI\Routing\RoutingStrategyRegistry;

/**
 * Shared scaffolding for the platform suites: register doubles in the runtime
 * registry, and reconfigure the platform mid-test.
 *
 * Everything the intelligence layer resolves is a singleton built from
 * {@see PlatformConfig}, so changing configuration means discarding those
 * instances — {@see self::configurePlatform()} does exactly that, keeping the
 * "configuration is read once" guarantee honest while still letting a test
 * exercise several configurations.
 */
trait InteractsWithProviderPlatform
{
    protected function providerRegistry(): ProviderRegistryInterface
    {
        return $this->app->make(ProviderRegistryInterface::class);
    }

    /**
     * Register a double under a key, declaring what it can do.
     *
     * @param  list<string>  $models
     */
    protected function registerProvider(
        string $key,
        AIProviderInterface $provider,
        int $priority = 0,
        bool $text = true,
        bool $image = false,
        bool $voice = false,
        array $models = [],
    ): AIProviderInterface {
        $this->providerRegistry()->register(
            $key,
            static fn (ProviderConfigDTO $config): AIProviderInterface => $provider,
            new ProviderCapabilityDTO(
                key: $key,
                name: ucfirst($key),
                version: '1.0.0-test',
                text: $text,
                image: $image,
                voice: $voice,
                models: $models,
            ),
            priority: $priority,
        );

        // A provider registered after something was cached must be visible.
        $this->app->make(ProviderInstanceCache::class)->flush();
        $this->app->make(ProviderMetadataCache::class)->flush();

        return $provider;
    }

    /**
     * Apply configuration overrides and rebuild everything derived from them.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function configurePlatform(array $overrides): void
    {
        config($overrides);

        foreach ([
            PlatformConfig::class,
            ProviderInstanceCache::class,
            ProviderMetadataCache::class,
            HealthTrackerInterface::class,
            CircuitBreakerInterface::class,
            RetryPolicyInterface::class,
            MetricsCollectorInterface::class,
            RoutingStrategyRegistry::class,
            CostEstimator::class,
            ModalityInvokerRegistry::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    protected function router(): ProviderRouterInterface
    {
        return $this->app->make(ProviderRouterInterface::class);
    }

    protected function dispatcher(): ProviderDispatcherInterface
    {
        return $this->app->make(ProviderDispatcherInterface::class);
    }

    protected function breaker(): CircuitBreakerInterface
    {
        return $this->app->make(CircuitBreakerInterface::class);
    }

    protected function healthTracker(): HealthTrackerInterface
    {
        return $this->app->make(HealthTrackerInterface::class);
    }

    protected function metrics(): MetricsCollectorInterface
    {
        return $this->app->make(MetricsCollectorInterface::class);
    }
}
