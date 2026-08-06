<?php

declare(strict_types=1);

namespace App\AI\Config;

use Throwable;

/**
 * The retry policy's settings: how many attempts a provider gets, how long the
 * platform waits between them, and which failures are worth repeating.
 *
 * Both exception lists are configuration, so classifying a new vendor failure
 * never requires editing the policy.
 */
final readonly class RetryConfig
{
    /**
     * @param  list<class-string>  $retryable  Transient failures worth repeating.
     * @param  list<class-string>  $nonRetryable  Deterministic failures; checked first.
     */
    public function __construct(
        public bool $enabled = true,
        public int $maxAttempts = 3,
        public int $delayMs = 200,
        public float $multiplier = 2.0,
        public int $maxDelayMs = 5_000,
        public bool $jitter = true,
        public float $jitterRatio = 0.25,
        public array $retryable = [],
        public array $nonRetryable = [],
    ) {}

    public static function fromReader(ConfigReader $reader): self
    {
        return new self(
            enabled: $reader->bool('enabled', true),
            maxAttempts: max(1, $reader->int('max_attempts', 3)),
            delayMs: max(0, $reader->int('delay_ms', 200)),
            multiplier: max(1.0, $reader->float('multiplier', 2.0)),
            maxDelayMs: max(0, $reader->int('max_delay_ms', 5_000)),
            jitter: $reader->bool('jitter', true),
            jitterRatio: max(0.0, min(1.0, $reader->float('jitter_ratio', 0.25))),
            retryable: $reader->classList('retryable'),
            nonRetryable: $reader->classList('non_retryable'),
        );
    }

    /**
     * The attempt budget for one provider, honouring a caller override.
     */
    public function attemptBudget(?int $override = null): int
    {
        if (! $this->enabled) {
            return 1;
        }

        return $override === null ? $this->maxAttempts : max(1, $override);
    }

    /**
     * Whether this failure is classified as transient. A non-retryable match
     * always wins; with no retryable list configured, nothing is retried.
     */
    public function isRetryable(Throwable $failure): bool
    {
        foreach ($this->nonRetryable as $class) {
            if ($failure instanceof $class) {
                return false;
            }
        }

        foreach ($this->retryable as $class) {
            if ($failure instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Backoff for the delay that follows the given (1-based) attempt, capped at
     * {@see self::$maxDelayMs}. Jitter is applied by the policy, which owns the
     * randomness; this stays a pure function of configuration.
     */
    public function backoffMs(int $attempt): int
    {
        if ($this->delayMs <= 0) {
            return 0;
        }

        $delay = (float) $this->delayMs * $this->multiplier ** max(0, $attempt - 1);

        return (int) min((float) $this->maxDelayMs, $delay);
    }
}
