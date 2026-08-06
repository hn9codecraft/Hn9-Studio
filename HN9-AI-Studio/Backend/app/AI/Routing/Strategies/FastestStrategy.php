<?php

declare(strict_types=1);

namespace App\AI\Routing\Strategies;

use App\AI\Contracts\RoutingStrategyInterface;
use App\AI\Routing\CandidateScale;
use App\AI\Routing\ProviderCandidate;
use App\AI\Routing\RoutingContext;

/**
 * Ranks by observed average response time, fastest first.
 *
 * Latency comes from recorded metrics, so this strategy is only as good as the
 * history behind it; a provider with no history scores neutral and is neither
 * favoured nor starved of the traffic that would give it one.
 */
final readonly class FastestStrategy implements RoutingStrategyInterface
{
    public const KEY = 'fastest';

    private const TIE_BREAK_WEIGHT = 0.01;

    public function key(): string
    {
        return self::KEY;
    }

    public function score(ProviderCandidate $candidate, CandidateScale $scale, RoutingContext $context): float
    {
        return $scale->speed($candidate->averageLatencyMs)
            + $scale->priority($candidate->priority) * self::TIE_BREAK_WEIGHT;
    }
}
