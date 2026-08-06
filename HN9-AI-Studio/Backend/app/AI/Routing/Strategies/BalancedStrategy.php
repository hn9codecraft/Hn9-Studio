<?php

declare(strict_types=1);

namespace App\AI\Routing\Strategies;

use App\AI\Config\RoutingConfig;
use App\AI\Contracts\RoutingStrategyInterface;
use App\AI\Routing\CandidateScale;
use App\AI\Routing\ProviderCandidate;
use App\AI\Routing\RoutingContext;

/**
 * The default strategy: a weighted blend of every signal.
 *
 * Weights come from configuration, so the trade-off between operator priority,
 * price, speed and observed reliability is a deployment decision rather than a
 * code change. Because each signal is already normalised to 0..1, the weights
 * are pure ratios and the result is divided by their sum — a deployment that
 * zeroes three of them gets exactly the fourth strategy, with no special case.
 */
final readonly class BalancedStrategy implements RoutingStrategyInterface
{
    public const KEY = 'balanced';

    public const SIGNAL_PRIORITY = 'priority';

    public const SIGNAL_COST = 'cost';

    public const SIGNAL_LATENCY = 'latency';

    public const SIGNAL_RELIABILITY = 'reliability';

    public function __construct(private RoutingConfig $config) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function score(ProviderCandidate $candidate, CandidateScale $scale, RoutingContext $context): float
    {
        $signals = [
            self::SIGNAL_PRIORITY => $scale->priority($candidate->priority),
            self::SIGNAL_COST => $scale->affordability($candidate->estimatedCost),
            self::SIGNAL_LATENCY => $scale->speed($candidate->averageLatencyMs),
            self::SIGNAL_RELIABILITY => $candidate->reliability,
        ];

        $total = 0.0;
        $weights = 0.0;

        foreach ($signals as $signal => $value) {
            $weight = $this->config->weight($signal);

            if ($weight <= 0.0) {
                continue;
            }

            $total += $value * $weight;
            $weights += $weight;
        }

        // No weights configured → fall back to the operator's own ordering.
        return $weights > 0.0 ? $total / $weights : $scale->priority($candidate->priority);
    }
}
