<?php

declare(strict_types=1);

namespace App\AI\Metrics;

use App\AI\Config\MetricsConfig;
use App\AI\Contracts\MetricsCollectorInterface;
use App\AI\DTOs\MetricsSnapshotDTO;
use App\AI\DTOs\ProviderMetricsDTO;
use App\AI\Support\Capability;
use Illuminate\Contracts\Cache\Repository;

/**
 * Counter-based metrics held in a cache store.
 *
 * Each measure is its own integer key so recording is a single atomic
 * increment — no read-modify-write, and therefore no lost updates when workers
 * record concurrently. Cost is accumulated in millionths of a currency unit,
 * because increments must be integral.
 *
 * The provider and capability indexes are the only non-counter keys; they exist
 * so a snapshot can enumerate what has been seen without scanning the store.
 */
final readonly class CacheMetricsCollector implements MetricsCollectorInterface
{
    /**
     * Cost is stored as an integer; this is the scale factor.
     */
    private const COST_SCALE = 1_000_000;

    public function __construct(
        private Repository $cache,
        private MetricsConfig $config,
    ) {}

    public function recordSuccess(string $provider, Capability $capability, int $durationMs, float $cost = 0.0): void
    {
        $this->index('providers', $provider);
        $this->index('capabilities', $capability->value);

        $this->bump($this->providerKey($provider, 'requests'));
        $this->bump($this->providerKey($provider, 'successes'));
        $this->bump($this->providerKey($provider, 'duration'), max(0, $durationMs));
        $this->bump($this->providerKey($provider, 'cost'), (int) round(max(0.0, $cost) * self::COST_SCALE));

        $this->bump($this->capabilityKey($capability, 'requests'));
        $this->bump($this->capabilityKey($capability, 'successes'));
        $this->bump($this->capabilityKey($capability, 'duration'), max(0, $durationMs));
    }

    public function recordFailure(string $provider, Capability $capability, int $durationMs, string $reason): void
    {
        $this->index('providers', $provider);
        $this->index('capabilities', $capability->value);

        $this->bump($this->providerKey($provider, 'requests'));
        $this->bump($this->providerKey($provider, 'failures'));
        $this->bump($this->providerKey($provider, 'duration'), max(0, $durationMs));

        $this->bump($this->capabilityKey($capability, 'requests'));
        $this->bump($this->capabilityKey($capability, 'failures'));
        $this->bump($this->capabilityKey($capability, 'duration'), max(0, $durationMs));

        // Reason counters explain a failure rate without needing the log.
        $this->bump($this->providerKey($provider, 'reason:'.$reason));
    }

    public function recordRetry(string $provider, Capability $capability): void
    {
        $this->index('providers', $provider);

        $this->bump($this->providerKey($provider, 'retries'));
        $this->bump($this->capabilityKey($capability, 'retries'));
    }

    public function recordFallback(string $from, string $to, Capability $capability): void
    {
        $this->index('providers', $from);
        $this->index('providers', $to);

        // Counted against the provider that gave the request up.
        $this->bump($this->providerKey($from, 'fallbacks'));
        $this->bump($this->capabilityKey($capability, 'fallbacks'));
    }

    public function forProvider(string $provider): ProviderMetricsDTO
    {
        return $this->read($provider, fn (string $measure): string => $this->providerKey($provider, $measure));
    }

    public function snapshot(): MetricsSnapshotDTO
    {
        $providers = [];

        foreach ($this->indexed('providers') as $provider) {
            $providers[$provider] = $this->forProvider($provider);
        }

        $capabilities = [];

        foreach ($this->indexed('capabilities') as $value) {
            $capability = Capability::tryFrom($value);

            if ($capability !== null) {
                $capabilities[$value] = $this->read(
                    $value,
                    fn (string $measure): string => $this->capabilityKey($capability, $measure),
                );
            }
        }

        return new MetricsSnapshotDTO($providers, $capabilities);
    }

    public function flush(): void
    {
        foreach (['providers' => 'p', 'capabilities' => 'c'] as $index => $namespace) {
            foreach ($this->indexed($index) as $member) {
                foreach (['requests', 'successes', 'failures', 'retries', 'fallbacks', 'duration', 'cost'] as $measure) {
                    $this->cache->forget($this->key($namespace, $member, $measure));
                }
            }

            $this->cache->forget($this->key('index', $index));
        }
    }

    /**
     * @param  callable(string): string  $key
     */
    private function read(string $name, callable $key): ProviderMetricsDTO
    {
        return new ProviderMetricsDTO(
            key: $name,
            requests: $this->count($key('requests')),
            successes: $this->count($key('successes')),
            failures: $this->count($key('failures')),
            retries: $this->count($key('retries')),
            fallbacks: $this->count($key('fallbacks')),
            totalDurationMs: $this->count($key('duration')),
            estimatedCost: $this->count($key('cost')) / self::COST_SCALE,
        );
    }

    /**
     * Increment a counter, seeding it first so the whole series shares one
     * retention window.
     */
    private function bump(string $key, int $by = 1): void
    {
        if ($by === 0) {
            return;
        }

        $this->cache->add($key, 0, $this->config->ttl);
        $this->cache->increment($key, $by);
    }

    private function count(string $key): int
    {
        $value = $this->cache->get($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Note a member in an index so snapshots can enumerate it.
     */
    private function index(string $index, string $member): void
    {
        $members = $this->indexed($index);

        if (in_array($member, $members, true)) {
            return;
        }

        $members[] = $member;

        $this->cache->put($this->key('index', $index), $members, $this->config->ttl);
    }

    /**
     * @return list<string>
     */
    private function indexed(string $index): array
    {
        $members = $this->cache->get($this->key('index', $index));

        if (! is_array($members)) {
            return [];
        }

        $list = [];

        foreach ($members as $member) {
            if (is_string($member)) {
                $list[] = $member;
            }
        }

        return $list;
    }

    private function providerKey(string $provider, string $measure): string
    {
        return $this->key('p', $provider, $measure);
    }

    private function capabilityKey(Capability $capability, string $measure): string
    {
        return $this->key('c', $capability->value, $measure);
    }

    private function key(string ...$segments): string
    {
        return $this->config->prefix.':'.implode(':', $segments);
    }
}
