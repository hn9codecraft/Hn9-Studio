<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use Throwable;

/**
 * Decides whether a failed provider attempt is worth repeating and how long to
 * wait before doing so.
 *
 * The policy classifies failures by type — never by provider — so retry
 * behaviour is uniform across the whole platform and configurable in one place.
 */
interface RetryPolicyInterface
{
    /**
     * Total attempts allowed against a single provider, including the first.
     */
    public function maxAttempts(): int;

    /**
     * Whether the given failure, on the given (1-based) attempt, should be
     * retried against the same provider.
     */
    public function shouldRetry(Throwable $failure, int $attempt): bool;

    /**
     * Milliseconds to wait after the given (1-based) attempt before retrying.
     */
    public function delayFor(int $attempt): int;

    /**
     * A copy of this policy with a different attempt budget, for callers that
     * override the configured default per dispatch.
     */
    public function withMaxAttempts(int $maxAttempts): self;
}
