<?php

declare(strict_types=1);

namespace App\AI\Config;

use App\AI\Support\ProviderConfigResolver;

/**
 * The parsed, strongly typed view of `config/ai.php` for the intelligence layer.
 *
 * Bound as a singleton, so the array is read and coerced once per process
 * rather than on every dispatch — this object *is* the configuration cache. The
 * provider blocks themselves stay with {@see ProviderConfigResolver};
 * nothing here duplicates that resolution.
 */
final readonly class PlatformConfig
{
    public function __construct(
        public RoutingConfig $routing = new RoutingConfig,
        public RetryConfig $retry = new RetryConfig,
        public CircuitBreakerConfig $circuitBreaker = new CircuitBreakerConfig,
        public CostConfig $cost = new CostConfig,
        public MetricsConfig $metrics = new MetricsConfig,
        public CacheConfig $cache = new CacheConfig,
        public TimeoutConfig $timeouts = new TimeoutConfig,
    ) {}

    /**
     * @param  array<string, mixed>  $config  The whole `ai` configuration array.
     */
    public static function fromArray(array $config): self
    {
        $reader = ConfigReader::of($config);

        return new self(
            routing: RoutingConfig::fromReader($reader->section('routing')),
            retry: RetryConfig::fromReader($reader->section('retry')),
            circuitBreaker: CircuitBreakerConfig::fromReader($reader->section('circuit_breaker')),
            cost: CostConfig::fromReader($reader->section('cost')),
            metrics: MetricsConfig::fromReader($reader->section('metrics')),
            cache: CacheConfig::fromReader(
                $reader->section('cache'),
                // The health TTL predates this layer and stays where operators expect it.
                healthTtl: $reader->section('health')->int('cache_ttl', 60),
            ),
            timeouts: TimeoutConfig::fromReader($reader->section('timeouts')),
        );
    }
}
