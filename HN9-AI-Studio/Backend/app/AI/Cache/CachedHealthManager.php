<?php

declare(strict_types=1);

namespace App\AI\Cache;

use App\AI\Config\CacheConfig;
use App\AI\Contracts\HealthManagerInterface;
use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\DTOs\ProviderHealthDTO;
use App\AI\Health\HealthManager;
use Illuminate\Contracts\Cache\Repository;

/**
 * Caches health probes for the configured TTL.
 *
 * A probe is a real vendor request. Without this, routing that consults health
 * would multiply every dispatch by the number of candidates. The decorator adds
 * caching only — probing, error isolation and aggregation stay with the wrapped
 * {@see HealthManager}, which is untouched.
 */
final readonly class CachedHealthManager implements HealthManagerInterface
{
    public function __construct(
        private HealthManagerInterface $inner,
        private ProviderRegistryInterface $registry,
        private Repository $cache,
        private CacheConfig $config,
    ) {}

    public function check(string $key): ProviderHealthDTO
    {
        $cached = $this->cache->get($this->cacheKey($key));

        if ($cached instanceof ProviderHealthDTO) {
            return $cached;
        }

        $health = $this->inner->check($key);

        $this->cache->put($this->cacheKey($key), $health, $this->config->healthTtl);

        return $health;
    }

    public function aggregate(): array
    {
        $report = [];

        // Enumerated here rather than delegated, so each provider's cached
        // result is reused instead of the aggregate being probed as a whole.
        foreach (array_keys($this->registry->enabled()) as $key) {
            $report[$key] = $this->check($key);
        }

        return $report;
    }

    /**
     * Drop a provider's cached probe, forcing the next check to be live.
     */
    public function forget(string $key): void
    {
        $this->cache->forget($this->cacheKey($key));
    }

    private function cacheKey(string $key): string
    {
        return $this->config->key('health', $key);
    }
}
