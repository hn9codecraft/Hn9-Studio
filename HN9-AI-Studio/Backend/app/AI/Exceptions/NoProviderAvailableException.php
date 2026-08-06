<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

use App\AI\Support\Capability;

/**
 * Thrown when routing finds nothing able to serve a request: no provider
 * declares the capability, or every candidate was filtered out by health,
 * circuit state, model support or an explicit exclusion.
 *
 * The context lists why each key was rejected, so an operator can tell a
 * misconfiguration from a genuine outage.
 */
final class NoProviderAvailableException extends AIException
{
    /**
     * @param  array<string, string>  $rejected  Provider key => rejection reason.
     */
    public static function forCapability(Capability $capability, array $rejected = []): self
    {
        return new self(
            message: "No AI provider is available for the [{$capability->value}] capability.",
            errorCode: 'ai_no_provider_available',
            statusCode: 503,
            context: ['capability' => $capability->value, 'rejected' => $rejected],
        );
    }
}
