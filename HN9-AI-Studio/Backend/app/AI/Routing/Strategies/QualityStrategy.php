<?php

declare(strict_types=1);

namespace App\AI\Routing\Strategies;

use App\AI\Contracts\RoutingStrategyInterface;
use App\AI\Routing\CandidateScale;
use App\AI\Routing\ProviderCandidate;
use App\AI\Routing\RoutingContext;

/**
 * Ranks for output quality, ignoring price entirely.
 *
 * Quality is not measurable from inside the platform, so it is taken to be what
 * operators encoded in the priority ordering — the same list they curate when
 * they decide which provider is their best. Observed reliability adjusts that
 * ranking, because a better model that keeps failing is not the better answer.
 */
final readonly class QualityStrategy implements RoutingStrategyInterface
{
    public const KEY = 'quality';

    private const PRIORITY_WEIGHT = 0.8;

    private const RELIABILITY_WEIGHT = 0.2;

    public function key(): string
    {
        return self::KEY;
    }

    public function score(ProviderCandidate $candidate, CandidateScale $scale, RoutingContext $context): float
    {
        return $scale->priority($candidate->priority) * self::PRIORITY_WEIGHT
            + $candidate->reliability * self::RELIABILITY_WEIGHT;
    }
}
