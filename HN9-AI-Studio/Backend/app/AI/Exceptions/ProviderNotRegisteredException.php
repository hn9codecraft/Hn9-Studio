<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

use App\AI\Registry\ProviderRegistry;

/**
 * Thrown when a provider key is requested that has not been registered in the
 * {@see ProviderRegistry}.
 */
class ProviderNotRegisteredException extends AIException
{
    public static function forKey(string $key): self
    {
        return new self(
            message: "No AI provider is registered under key [{$key}].",
            errorCode: 'ai_provider_not_registered',
            statusCode: 404,
            context: ['key' => $key],
        );
    }
}
