<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

use App\AI\Support\Capability;

/**
 * Thrown when a provider is asked to perform a modality/capability it does not
 * support (e.g. requesting video from a text-only provider).
 */
class UnsupportedCapabilityException extends AIException
{
    public static function make(string $providerKey, Capability $capability): self
    {
        return new self(
            message: "Provider [{$providerKey}] does not support the [{$capability->value}] capability.",
            errorCode: 'ai_unsupported_capability',
            statusCode: 422,
            context: ['provider' => $providerKey, 'capability' => $capability->value],
        );
    }
}
