<?php

declare(strict_types=1);

namespace App\AI\Support;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * The state of a provider's circuit breaker.
 *
 * Closed is the normal path. Open means the provider has failed past its
 * threshold and is withheld from routing until the recovery timeout elapses.
 * HalfOpen is the trial window that follows: a limited number of probes decide
 * whether the provider is restored (Closed) or withheld again (Open).
 */
enum CircuitState: string
{
    use InteractsWithEnum;

    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';

    /**
     * Whether a request may be dispatched while the circuit is in this state.
     * An open circuit answers false until the breaker itself promotes it.
     */
    public function allowsTraffic(): bool
    {
        return $this !== self::Open;
    }

    public function label(): string
    {
        return match ($this) {
            self::HalfOpen => 'Half-Open',
            default => ucfirst($this->value),
        };
    }
}
