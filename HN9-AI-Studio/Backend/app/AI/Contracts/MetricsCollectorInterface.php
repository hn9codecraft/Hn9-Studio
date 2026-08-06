<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\DTOs\MetricsSnapshotDTO;
use App\AI\DTOs\ProviderMetricsDTO;
use App\AI\Support\Capability;

/**
 * Records how the platform behaves: throughput, outcome, latency, retries,
 * fallbacks and estimated spend, sliced by provider and by capability.
 *
 * Recording is fire-and-forget — a metrics failure must never fail a dispatch.
 */
interface MetricsCollectorInterface
{
    /**
     * A call that returned a response.
     *
     * @param  float  $cost  Estimated cost of the call in the configured currency.
     */
    public function recordSuccess(string $provider, Capability $capability, int $durationMs, float $cost = 0.0): void;

    /**
     * A call that raised. `$reason` is the typed error code, never a provider name.
     */
    public function recordFailure(string $provider, Capability $capability, int $durationMs, string $reason): void;

    /**
     * An attempt repeated against the same provider.
     */
    public function recordRetry(string $provider, Capability $capability): void;

    /**
     * A request handed from one provider to the next.
     */
    public function recordFallback(string $from, string $to, Capability $capability): void;

    /**
     * Everything recorded for one provider.
     */
    public function forProvider(string $provider): ProviderMetricsDTO;

    /**
     * The whole platform's counters.
     */
    public function snapshot(): MetricsSnapshotDTO;

    /**
     * Discard all recorded counters.
     */
    public function flush(): void;
}
