<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

/**
 * Thrown when a registered provider is resolved while disabled.
 */
class ProviderDisabledException extends AIException
{
    public static function forKey(string $key): self
    {
        return new self(
            message: "AI provider [{$key}] is disabled.",
            errorCode: 'ai_provider_disabled',
            statusCode: 409,
            context: ['key' => $key],
        );
    }
}
