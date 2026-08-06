<?php

declare(strict_types=1);

namespace App\AI\Metrics;

use App\AI\Contracts\MetricsCollectorInterface;
use App\AI\DTOs\MetricsSnapshotDTO;
use App\AI\DTOs\ProviderMetricsDTO;
use App\AI\Support\Capability;

/**
 * The collector bound when metrics are disabled (Null Object pattern).
 *
 * Swapping the implementation keeps the dispatcher free of "are metrics on?"
 * branches — the hot path calls the same methods either way, and here they cost
 * a no-op.
 */
final readonly class NullMetricsCollector implements MetricsCollectorInterface
{
    public function recordSuccess(string $provider, Capability $capability, int $durationMs, float $cost = 0.0): void
    {
        //
    }

    public function recordFailure(string $provider, Capability $capability, int $durationMs, string $reason): void
    {
        //
    }

    public function recordRetry(string $provider, Capability $capability): void
    {
        //
    }

    public function recordFallback(string $from, string $to, Capability $capability): void
    {
        //
    }

    public function forProvider(string $provider): ProviderMetricsDTO
    {
        return new ProviderMetricsDTO($provider);
    }

    public function snapshot(): MetricsSnapshotDTO
    {
        return new MetricsSnapshotDTO;
    }

    public function flush(): void
    {
        //
    }
}
