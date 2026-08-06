<?php

declare(strict_types=1);

namespace App\AI\Routing;

/**
 * Normalises the candidate set's raw signals to 0..1.
 *
 * Priorities, prices and latencies live on incomparable scales, and their
 * ranges differ per capability and per deployment. Scaling relative to the
 * candidates actually in play lets one set of weights stay meaningful
 * everywhere, and keeps every strategy free of absolute thresholds.
 *
 * A signal with no spread (or no data) normalises to a neutral 0.5, so it
 * neither dominates the score nor silently zeroes a candidate out.
 */
final readonly class CandidateScale
{
    private const NEUTRAL = 0.5;

    private function __construct(
        private int $minPriority,
        private int $maxPriority,
        private ?float $minCost,
        private ?float $maxCost,
        private ?float $minLatency,
        private ?float $maxLatency,
    ) {}

    /**
     * @param  list<ProviderCandidate>  $candidates
     */
    public static function of(array $candidates): self
    {
        $priorities = array_map(static fn (ProviderCandidate $c): int => $c->priority, $candidates);
        $costs = self::present(array_map(static fn (ProviderCandidate $c): ?float => $c->estimatedCost, $candidates));
        $latencies = self::present(array_map(static fn (ProviderCandidate $c): ?float => $c->averageLatencyMs, $candidates));

        return new self(
            minPriority: $priorities === [] ? 0 : min($priorities),
            maxPriority: $priorities === [] ? 0 : max($priorities),
            minCost: $costs === [] ? null : min($costs),
            maxCost: $costs === [] ? null : max($costs),
            minLatency: $latencies === [] ? null : min($latencies),
            maxLatency: $latencies === [] ? null : max($latencies),
        );
    }

    /**
     * Higher priority scores higher.
     */
    public function priority(int $priority): float
    {
        return self::normalise((float) $priority, (float) $this->minPriority, (float) $this->maxPriority);
    }

    /**
     * Cheaper scores higher. An unestimated candidate scores neutral.
     */
    public function affordability(?float $cost): float
    {
        if ($cost === null || $this->minCost === null || $this->maxCost === null) {
            return self::NEUTRAL;
        }

        return 1.0 - self::normalise($cost, $this->minCost, $this->maxCost);
    }

    /**
     * Faster scores higher. A candidate with no history scores neutral.
     */
    public function speed(?float $latencyMs): float
    {
        if ($latencyMs === null || $this->minLatency === null || $this->maxLatency === null) {
            return self::NEUTRAL;
        }

        return 1.0 - self::normalise($latencyMs, $this->minLatency, $this->maxLatency);
    }

    /**
     * Min-max scaling; a zero-width range is neutral rather than a division by
     * zero or an arbitrary winner.
     */
    private static function normalise(float $value, float $min, float $max): float
    {
        $range = $max - $min;

        if ($range <= 0.0) {
            return self::NEUTRAL;
        }

        return max(0.0, min(1.0, ($value - $min) / $range));
    }

    /**
     * @param  list<float|null>  $values
     * @return list<float>
     */
    private static function present(array $values): array
    {
        $present = [];

        foreach ($values as $value) {
            if ($value !== null) {
                $present[] = $value;
            }
        }

        return $present;
    }
}
