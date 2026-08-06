<?php

declare(strict_types=1);

namespace App\AI\DTOs;

/**
 * Everything recorded about one provider (or, in aggregate form, one
 * capability). Rates and averages are derived here rather than stored, so the
 * counters remain simple monotonic integers that any cache store can increment.
 */
final readonly class ProviderMetricsDTO
{
    public function __construct(
        public string $key,
        public int $requests = 0,
        public int $successes = 0,
        public int $failures = 0,
        public int $retries = 0,
        public int $fallbacks = 0,
        public int $totalDurationMs = 0,
        public float $estimatedCost = 0.0,
    ) {}

    public function successRate(): float
    {
        return $this->requests === 0 ? 0.0 : round($this->successes / $this->requests, 4);
    }

    public function failureRate(): float
    {
        return $this->requests === 0 ? 0.0 : round($this->failures / $this->requests, 4);
    }

    public function averageResponseMs(): float
    {
        return $this->requests === 0 ? 0.0 : round($this->totalDurationMs / $this->requests, 2);
    }

    /**
     * Reliability as a 0..1 routing signal. A provider with no history scores
     * neutral rather than perfect, so it is neither favoured nor punished for
     * being new.
     */
    public function reliability(): float
    {
        return $this->requests === 0 ? 0.5 : $this->successRate();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'requests' => $this->requests,
            'successes' => $this->successes,
            'failures' => $this->failures,
            'retries' => $this->retries,
            'fallbacks' => $this->fallbacks,
            'success_rate' => $this->successRate(),
            'failure_rate' => $this->failureRate(),
            'average_response_ms' => $this->averageResponseMs(),
            'estimated_cost' => round($this->estimatedCost, 6),
        ];
    }
}
