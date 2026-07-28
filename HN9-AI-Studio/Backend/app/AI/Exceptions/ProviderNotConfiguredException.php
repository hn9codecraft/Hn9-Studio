<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

/**
 * Thrown when required provider configuration is missing — e.g. no default
 * provider is configured, or a provider's config block is absent.
 */
class ProviderNotConfiguredException extends AIException
{
    public static function noDefault(): self
    {
        return new self(
            message: 'No default AI provider is configured.',
            errorCode: 'ai_no_default_provider',
            statusCode: 409,
        );
    }

    public static function forKey(string $key): self
    {
        return new self(
            message: "AI provider [{$key}] is not configured.",
            errorCode: 'ai_provider_not_configured',
            statusCode: 409,
            context: ['key' => $key],
        );
    }
}
