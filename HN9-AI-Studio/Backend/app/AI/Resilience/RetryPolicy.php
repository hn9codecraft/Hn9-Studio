<?php

declare(strict_types=1);

namespace App\AI\Resilience;

use App\AI\Config\RetryConfig;
use App\AI\Contracts\RetryPolicyInterface;
use Throwable;

/**
 * The configured retry policy.
 *
 * Classification is by exception type from configuration: a non-retryable match
 * always wins, so a failure listed in both lists is never repeated. Backoff is
 * exponential and capped, with optional jitter to keep concurrent callers from
 * retrying in lockstep after a shared outage.
 */
final readonly class RetryPolicy implements RetryPolicyInterface
{
    public function __construct(
        private RetryConfig $config,
        private ?int $maxAttempts = null,
    ) {}

    public function maxAttempts(): int
    {
        return $this->config->attemptBudget($this->maxAttempts);
    }

    public function shouldRetry(Throwable $failure, int $attempt): bool
    {
        if ($attempt >= $this->maxAttempts()) {
            return false;
        }

        return $this->config->isRetryable($failure);
    }

    public function delayFor(int $attempt): int
    {
        $delay = $this->config->backoffMs($attempt);

        if ($delay <= 0 || ! $this->config->jitter || $this->config->jitterRatio <= 0.0) {
            return $delay;
        }

        $spread = (int) round($delay * $this->config->jitterRatio);

        return $spread <= 0 ? $delay : $delay + random_int(0, $spread);
    }

    public function withMaxAttempts(int $maxAttempts): RetryPolicyInterface
    {
        return new self($this->config, max(1, $maxAttempts));
    }
}
