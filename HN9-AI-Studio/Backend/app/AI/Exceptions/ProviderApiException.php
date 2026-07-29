<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

final class ProviderApiException extends AIException
{
    /** @param array<string, mixed> $context */
    public static function forProvider(string $provider, string $message, int $statusCode = 502, array $context = []): self
    {
        return new self($message, 'ai_provider_api_failure', $statusCode, ['provider' => $provider, ...$context]);
    }
}
