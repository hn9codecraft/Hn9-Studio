<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

use App\AI\Support\Capability;
use Throwable;

/**
 * Thrown when every provider in the routing plan has been tried and none
 * succeeded. The attempt log — one entry per provider attempt, including those
 * skipped for an open circuit or an exhausted deadline — travels in the
 * context, and the last underlying failure is kept as the previous exception so
 * the original cause is never lost.
 */
final class AllProvidersFailedException extends AIException
{
    /**
     * @param  list<array<string, mixed>>  $attempts
     */
    public static function make(Capability $capability, array $attempts, ?Throwable $previous = null): self
    {
        return new self(
            message: "Every AI provider routed for the [{$capability->value}] capability failed.",
            errorCode: 'ai_all_providers_failed',
            statusCode: 502,
            context: [
                'capability' => $capability->value,
                'attempts' => $attempts,
                'last_error' => $previous?->getMessage(),
            ],
            previous: $previous,
        );
    }
}
