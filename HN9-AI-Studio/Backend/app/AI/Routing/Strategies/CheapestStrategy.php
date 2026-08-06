<?php

declare(strict_types=1);

namespace App\AI\Routing\Strategies;

use App\AI\Contracts\RoutingStrategyInterface;
use App\AI\Routing\CandidateScale;
use App\AI\Routing\ProviderCandidate;
use App\AI\Routing\RoutingContext;

/**
 * Ranks by estimated cost, cheapest first.
 *
 * Priority still breaks ties, so among providers that would cost the same — or
 * whose cost could not be estimated — the operator's ordering decides rather
 * than the iteration order of the registry.
 */
final readonly class CheapestStrategy implements RoutingStrategyInterface
{
    public const KEY = 'cheapest';

    /**
     * Small enough that priority can only separate equal-cost candidates.
     */
    private const TIE_BREAK_WEIGHT = 0.01;

    public function key(): string
    {
        return self::KEY;
    }

    public function score(ProviderCandidate $candidate, CandidateScale $scale, RoutingContext $context): float
    {
        return $scale->affordability($candidate->estimatedCost)
            + $scale->priority($candidate->priority) * self::TIE_BREAK_WEIGHT;
    }
}
