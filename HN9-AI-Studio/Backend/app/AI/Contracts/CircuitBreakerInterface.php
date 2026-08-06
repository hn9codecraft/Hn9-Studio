<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\Support\CircuitState;

/**
 * Per-provider circuit breaker.
 *
 * Consecutive failures open a provider's circuit, removing it from routing
 * until the recovery timeout elapses. The next call is then admitted as a
 * half-open trial: enough successes close the circuit, a single failure
 * re-opens it. Recovery therefore needs no operator action and no scheduler.
 */
interface CircuitBreakerInterface
{
    /**
     * Whether a request may be dispatched to the provider right now. This may
     * promote an expired open circuit to half-open as a side effect.
     */
    public function allows(string $provider): bool;

    /**
     * Record a successful call, closing a half-open circuit once its success
     * threshold is met.
     */
    public function recordSuccess(string $provider): void;

    /**
     * Record a failing call, opening the circuit at the failure threshold.
     */
    public function recordFailure(string $provider): void;

    /**
     * The provider's current state, without promoting anything.
     */
    public function state(string $provider): CircuitState;

    /**
     * Consecutive failures currently recorded against the provider.
     */
    public function failures(string $provider): int;

    /**
     * Seconds before an open circuit admits a trial call; zero when it is not
     * open. Surfaced so a caller can be told when to come back.
     */
    public function retryAfter(string $provider): int;

    /**
     * Forget a provider's circuit, returning it to closed.
     */
    public function reset(string $provider): void;
}
