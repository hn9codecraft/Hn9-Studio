<?php

declare(strict_types=1);

namespace App\AI\Routing;

use App\AI\Support\CircuitState;
use App\AI\Support\HealthStatus;

/**
 * One provider considered for a dispatch, together with the signals routing
 * ranks it by.
 *
 * The candidate carries raw measurements only — priority, cost, latency,
 * reliability, health, circuit state. Turning those into an order is the
 * strategy's job, and normalising them is {@see CandidateScale}'s, so a
 * strategy never needs to know what a "good" latency is in absolute terms.
 */
final readonly class ProviderCandidate
{
    /**
     * @param  int  $priority  Registration priority; higher is preferred.
     * @param  float|null  $estimatedCost  Null when not estimated or not estimable.
     * @param  float|null  $averageLatencyMs  Null with no recorded history.
     * @param  float  $reliability  Observed success rate, 0..1 (0.5 when unknown).
     * @param  int|null  $chainRank  Position in the configured fallback chain.
     * @param  int|null  $preferenceRank  Position in the caller's preference list.
     */
    public function __construct(
        public string $key,
        public int $priority,
        public HealthStatus $health = HealthStatus::Unknown,
        public CircuitState $circuit = CircuitState::Closed,
        public ?float $estimatedCost = null,
        public ?float $averageLatencyMs = null,
        public float $reliability = 0.5,
        public ?int $chainRank = null,
        public ?int $preferenceRank = null,
        public float $score = 0.0,
    ) {}

    /**
     * The same candidate with its final score attached.
     */
    public function scored(float $score): self
    {
        return new self(
            key: $this->key,
            priority: $this->priority,
            health: $this->health,
            circuit: $this->circuit,
            estimatedCost: $this->estimatedCost,
            averageLatencyMs: $this->averageLatencyMs,
            reliability: $this->reliability,
            chainRank: $this->chainRank,
            preferenceRank: $this->preferenceRank,
            score: $score,
        );
    }

    public function isPreferred(): bool
    {
        return $this->preferenceRank !== null;
    }

    public function isChained(): bool
    {
        return $this->chainRank !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'priority' => $this->priority,
            'health' => $this->health->value,
            'circuit' => $this->circuit->value,
            'estimated_cost' => $this->estimatedCost,
            'average_latency_ms' => $this->averageLatencyMs,
            'reliability' => round($this->reliability, 4),
            'chain_rank' => $this->chainRank,
            'preference_rank' => $this->preferenceRank,
            'score' => round($this->score, 6),
        ];
    }
}
