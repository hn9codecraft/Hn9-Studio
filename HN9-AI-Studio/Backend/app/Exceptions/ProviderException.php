<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * Raised for AI-provider configuration/registry problems in the domain layer
 * (unknown provider slug, disabled provider, missing required setting).
 *
 * This concerns provider *metadata* only — it is not an integration/transport
 * error, as no provider clients exist in this sprint.
 */
class ProviderException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'A provider error occurred.',
        string $errorCode = 'provider_error',
        int $statusCode = 422,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $statusCode, $context, $previous);
    }

    public static function unknown(string $slug): self
    {
        return new self(
            message: "Unknown AI provider [{$slug}].",
            errorCode: 'provider_unknown',
            statusCode: 404,
            context: ['slug' => $slug],
        );
    }

    public static function inactive(string $slug): self
    {
        return new self(
            message: "AI provider [{$slug}] is not active.",
            errorCode: 'provider_inactive',
            statusCode: 409,
            context: ['slug' => $slug],
        );
    }

    public static function missingSetting(string $slug, string $key): self
    {
        return new self(
            message: "AI provider [{$slug}] is missing required setting [{$key}].",
            errorCode: 'provider_setting_missing',
            statusCode: 422,
            context: ['slug' => $slug, 'key' => $key],
        );
    }
}
