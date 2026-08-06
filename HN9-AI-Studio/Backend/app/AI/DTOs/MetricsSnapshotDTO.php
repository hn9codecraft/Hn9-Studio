<?php

declare(strict_types=1);

namespace App\AI\DTOs;

/**
 * The platform's counters at a point in time: usage per provider, usage per
 * capability, and the totals across both.
 */
final readonly class MetricsSnapshotDTO
{
    /**
     * @param  array<string, ProviderMetricsDTO>  $providers
     * @param  array<string, ProviderMetricsDTO>  $capabilities
     */
    public function __construct(
        public array $providers = [],
        public array $capabilities = [],
    ) {}

    public function totals(): ProviderMetricsDTO
    {
        $requests = $successes = $failures = $retries = $fallbacks = $duration = 0;
        $cost = 0.0;

        foreach ($this->providers as $metrics) {
            $requests += $metrics->requests;
            $successes += $metrics->successes;
            $failures += $metrics->failures;
            $retries += $metrics->retries;
            $fallbacks += $metrics->fallbacks;
            $duration += $metrics->totalDurationMs;
            $cost += $metrics->estimatedCost;
        }

        return new ProviderMetricsDTO(
            key: 'total',
            requests: $requests,
            successes: $successes,
            failures: $failures,
            retries: $retries,
            fallbacks: $fallbacks,
            totalDurationMs: $duration,
            estimatedCost: $cost,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'totals' => $this->totals()->toArray(),
            'providers' => array_map(
                static fn (ProviderMetricsDTO $metrics): array => $metrics->toArray(),
                $this->providers,
            ),
            'capabilities' => array_map(
                static fn (ProviderMetricsDTO $metrics): array => $metrics->toArray(),
                $this->capabilities,
            ),
        ];
    }
}
