<?php

declare(strict_types=1);

namespace App\AI\Routing\Strategies;

use App\AI\Contracts\RoutingStrategyInterface;
use App\AI\Routing\CandidateScale;
use App\AI\Routing\ProviderCandidate;
use App\AI\Routing\RoutingContext;

/**
 * Ranks purely by the registration priority operators configured.
 *
 * The most predictable strategy and the one to reach for when selection should
 * be an operator decision rather than a measurement.
 */
final readonly class PriorityStrategy implements RoutingStrategyInterface
{
    public const KEY = 'priority';

    public function key(): string
    {
        return self::KEY;
    }

    public function score(ProviderCandidate $candidate, CandidateScale $scale, RoutingContext $context): float
    {
        return $scale->priority($candidate->priority);
    }
}
