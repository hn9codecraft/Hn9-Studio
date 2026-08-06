<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

/**
 * Thrown when every provider that could have served a request is withheld by
 * its circuit breaker.
 *
 * Deliberately distinct from {@see NoProviderAvailableException}: nothing is
 * misconfigured and nothing is missing — the providers are breaking, and the
 * request can be expected to succeed again shortly. `retry_after` says when.
 */
final class CircuitOpenException extends AIException
{
    /**
     * @param  array<string, int>  $providers  Provider key => seconds until it admits traffic.
     */
    public static function forProviders(array $providers): self
    {
        $keys = implode(', ', array_keys($providers));

        return new self(
            message: "Every AI provider for this request has an open circuit: [{$keys}].",
            errorCode: 'ai_circuit_open',
            statusCode: 503,
            context: [
                'providers' => $providers,
                'retry_after' => $providers === [] ? 0 : min($providers),
            ],
        );
    }
}
