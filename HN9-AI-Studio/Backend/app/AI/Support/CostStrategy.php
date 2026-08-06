<?php

declare(strict_types=1);

namespace App\AI\Support;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * The caller's cost preference for a dispatch.
 *
 * Each case names the routing strategy that expresses it, so a cost preference
 * resolves to a strategy through the registry rather than through a conditional
 * in the router.
 */
enum CostStrategy: string
{
    use InteractsWithEnum;

    case Cheapest = 'cheapest';
    case Balanced = 'balanced';
    case Quality = 'quality';

    /**
     * The key of the routing strategy that implements this preference.
     */
    public function strategyKey(): string
    {
        return $this->value;
    }

    /**
     * Whether selection under this preference needs a cost estimate. Estimation
     * is skipped entirely when it cannot change the outcome.
     */
    public function needsEstimate(): bool
    {
        return $this !== self::Quality;
    }

    public function label(): string
    {
        return match ($this) {
            self::Quality => 'Highest Quality',
            default => ucfirst($this->value),
        };
    }
}
