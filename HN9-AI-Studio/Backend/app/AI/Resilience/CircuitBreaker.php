<?php

declare(strict_types=1);

namespace App\AI\Resilience;

use App\AI\Config\CircuitBreakerConfig;
use App\AI\Contracts\CircuitBreakerInterface;
use App\AI\Support\CircuitState;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;

/**
 * Cache-backed circuit breaker, one circuit per provider.
 *
 *   closed     — traffic flows; consecutive failures accumulate.
 *   open       — the failure threshold was reached; traffic is withheld until
 *                the recovery timeout elapses.
 *   half-open  — the timeout has elapsed; trial calls are admitted. Enough
 *                consecutive successes close the circuit; a single failure
 *                re-opens it and restarts the timeout.
 *
 * Because state lives in the cache store, the circuit is shared across workers
 * rather than being relearned per process. Recovery is automatic and needs no
 * scheduler: the promotion to half-open happens on the first call after the
 * timeout, as a side effect of asking whether traffic is allowed.
 */
final readonly class CircuitBreaker implements CircuitBreakerInterface
{
    public function __construct(
        private Repository $cache,
        private CircuitBreakerConfig $config,
    ) {}

    public function allows(string $provider): bool
    {
        if (! $this->config->enabled) {
            return true;
        }

        $state = $this->read($provider);

        if ($state['state'] !== CircuitState::Open) {
            return true;
        }

        if ($this->secondsUntilRecovery($state['opened_at']) > 0) {
            return false;
        }

        // The recovery window has passed: admit trial traffic.
        $this->write($provider, [...$state, 'state' => CircuitState::HalfOpen, 'successes' => 0]);

        return true;
    }

    public function recordSuccess(string $provider): void
    {
        if (! $this->config->enabled) {
            return;
        }

        $state = $this->read($provider);

        if ($state['state'] === CircuitState::HalfOpen) {
            $successes = $state['successes'] + 1;

            if ($successes < $this->config->successThreshold) {
                $this->write($provider, [...$state, 'successes' => $successes]);

                return;
            }
        }

        $this->reset($provider);
    }

    public function recordFailure(string $provider): void
    {
        if (! $this->config->enabled) {
            return;
        }

        $state = $this->read($provider);
        $failures = $state['failures'] + 1;

        // A failed trial re-opens immediately — the provider is not back yet.
        $reopen = $state['state'] === CircuitState::HalfOpen
            || $failures >= $this->config->failureThreshold;

        $this->write($provider, [
            'state' => $reopen ? CircuitState::Open : CircuitState::Closed,
            'failures' => $failures,
            'successes' => 0,
            'opened_at' => $reopen ? Carbon::now()->getTimestamp() : $state['opened_at'],
        ]);
    }

    public function state(string $provider): CircuitState
    {
        if (! $this->config->enabled) {
            return CircuitState::Closed;
        }

        $state = $this->read($provider);

        // Report the state an incoming call would meet, without promoting it.
        if ($state['state'] === CircuitState::Open && $this->secondsUntilRecovery($state['opened_at']) <= 0) {
            return CircuitState::HalfOpen;
        }

        return $state['state'];
    }

    public function failures(string $provider): int
    {
        return $this->read($provider)['failures'];
    }

    public function reset(string $provider): void
    {
        $this->cache->forget($this->cacheKey($provider));
    }

    /**
     * Seconds a caller must wait before the circuit accepts trial traffic.
     */
    public function retryAfter(string $provider): int
    {
        $state = $this->read($provider);

        return $state['state'] === CircuitState::Open
            ? $this->secondsUntilRecovery($state['opened_at'])
            : 0;
    }

    private function secondsUntilRecovery(?int $openedAt): int
    {
        if ($openedAt === null) {
            return 0;
        }

        $elapsed = Carbon::now()->getTimestamp() - $openedAt;

        return (int) max(0, $this->config->recoveryTimeout - $elapsed);
    }

    /**
     * @return array{state: CircuitState, failures: int, successes: int, opened_at: int|null}
     */
    private function read(string $provider): array
    {
        $stored = $this->cache->get($this->cacheKey($provider));

        if (! is_array($stored)) {
            return ['state' => CircuitState::Closed, 'failures' => 0, 'successes' => 0, 'opened_at' => null];
        }

        $state = $stored['state'] ?? null;

        return [
            'state' => $state instanceof CircuitState
                ? $state
                : (is_string($state) ? CircuitState::tryFrom($state) ?? CircuitState::Closed : CircuitState::Closed),
            'failures' => is_numeric($stored['failures'] ?? null) ? (int) $stored['failures'] : 0,
            'successes' => is_numeric($stored['successes'] ?? null) ? (int) $stored['successes'] : 0,
            'opened_at' => is_numeric($stored['opened_at'] ?? null) ? (int) $stored['opened_at'] : null,
        ];
    }

    /**
     * @param  array{state: CircuitState, failures: int, successes: int, opened_at: int|null}  $state
     */
    private function write(string $provider, array $state): void
    {
        $this->cache->put($this->cacheKey($provider), $state, $this->config->stateTtl());
    }

    private function cacheKey(string $provider): string
    {
        return $this->config->prefix.':'.$provider;
    }
}
