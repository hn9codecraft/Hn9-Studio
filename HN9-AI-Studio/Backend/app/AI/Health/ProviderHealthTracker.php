<?php

declare(strict_types=1);

namespace App\AI\Health;

use App\AI\Config\CacheConfig;
use App\AI\Config\HealthRoutingConfig;
use App\AI\Contracts\HealthTrackerInterface;
use App\AI\DTOs\ProviderHealthDTO;
use App\AI\Support\HealthStatus;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Health inferred from the calls the platform has already made.
 *
 * Every dispatch reports its outcome here, so routing can demote a struggling
 * provider without spending a probe. A run of consecutive failures moves a
 * provider Healthy → Degraded → Unavailable; one success clears the run
 * outright.
 *
 * Recovery is the entry's TTL. Observed state is held only for
 * `recovery_seconds`, so a provider that stops being called — or stops failing
 * — lapses back to Unknown and becomes routable again with no scheduler, no
 * operator action and no background job.
 */
final readonly class ProviderHealthTracker implements HealthTrackerInterface
{
    public function __construct(
        private Repository $cache,
        private CacheConfig $cacheConfig,
        private HealthRoutingConfig $config,
    ) {}

    public function recordSuccess(string $provider, int $latencyMs): void
    {
        $this->write($provider, [
            'failures' => 0,
            'latency_ms' => $latencyMs,
            'message' => null,
            'observed_at' => Carbon::now()->toIso8601String(),
        ]);
    }

    public function recordFailure(string $provider, Throwable $failure): void
    {
        $state = $this->read($provider);

        $this->write($provider, [
            'failures' => $state['failures'] + 1,
            'latency_ms' => null,
            'message' => $failure->getMessage(),
            'observed_at' => Carbon::now()->toIso8601String(),
        ]);
    }

    public function status(string $provider): HealthStatus
    {
        $state = $this->read($provider);

        if ($state['observed_at'] === null) {
            return HealthStatus::Unknown;
        }

        return $this->config->statusForFailures($state['failures']);
    }

    public function snapshot(string $provider): ProviderHealthDTO
    {
        $state = $this->read($provider);
        $status = $this->status($provider);

        return new ProviderHealthDTO(
            key: $provider,
            status: $status,
            latencyMs: $state['latency_ms'],
            message: $status === HealthStatus::Unknown ? 'No calls observed.' : $state['message'],
            checkedAt: $state['observed_at'],
            details: ['consecutive_failures' => $state['failures'], 'source' => 'observed'],
        );
    }

    public function forget(string $provider): void
    {
        $this->cache->forget($this->cacheKey($provider));
    }

    /**
     * @return array{failures: int, latency_ms: int|null, message: string|null, observed_at: string|null}
     */
    private function read(string $provider): array
    {
        $state = $this->cache->get($this->cacheKey($provider));

        if (! is_array($state)) {
            return ['failures' => 0, 'latency_ms' => null, 'message' => null, 'observed_at' => null];
        }

        return [
            'failures' => is_numeric($state['failures'] ?? null) ? (int) $state['failures'] : 0,
            'latency_ms' => is_numeric($state['latency_ms'] ?? null) ? (int) $state['latency_ms'] : null,
            'message' => is_string($state['message'] ?? null) ? $state['message'] : null,
            'observed_at' => is_string($state['observed_at'] ?? null) ? $state['observed_at'] : null,
        ];
    }

    /**
     * @param  array{failures: int, latency_ms: int|null, message: string|null, observed_at: string}  $state
     */
    private function write(string $provider, array $state): void
    {
        // Writing with the recovery TTL is what makes recovery automatic.
        $this->cache->put($this->cacheKey($provider), $state, $this->config->recoverySeconds);
    }

    private function cacheKey(string $provider): string
    {
        return $this->cacheConfig->key('observed-health', $provider);
    }
}
