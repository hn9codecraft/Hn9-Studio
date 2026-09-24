<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

use App\AI\Support\Capability;

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
            context: ['reason' => 'not_configured'],
        );
    }

    public static function forKey(string $key): self
    {
        return new self(
            message: "AI provider [{$key}] is not configured.",
            errorCode: 'ai_provider_not_configured',
            statusCode: 409,
            context: ['key' => $key, 'reason' => 'not_configured'],
        );
    }

    public static function forCapability(Capability $capability): self
    {
        return new self(
            message: 'No AI provider is configured for this capability. Enable a compatible provider and supply its API credentials in runtime configuration.',
            errorCode: 'ai_provider_not_configured',
            statusCode: 409,
            context: [
                'capability' => $capability->value,
                'reason' => 'not_configured',
            ],
        );
    }
}
