<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\Routing\CandidateScale;
use App\AI\Routing\ProviderCandidate;
use App\AI\Routing\RoutingContext;

/**
 * Ranks provider candidates for one dispatch (Strategy pattern).
 *
 * A strategy sees normalised signals only — priority, cost, latency and
 * reliability scaled to 0..1 across the candidate set — never a provider key,
 * so no strategy can grow a provider-specific branch. New selection policies
 * are added by registering another implementation, never by editing the router.
 */
interface RoutingStrategyInterface
{
    /**
     * The configuration key this strategy answers to (e.g. "cheapest").
     */
    public function key(): string;

    /**
     * Rank a candidate; higher is better. Implementations should return a value
     * in the 0..1 range so health penalties and preference boosts stay
     * comparable across strategies.
     */
    public function score(ProviderCandidate $candidate, CandidateScale $scale, RoutingContext $context): float;
}
