<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

final class ProviderTimeoutException extends AIException
{
    public static function forProvider(string $provider, ?\Throwable $previous = null): self
    {
        return new self("AI provider [{$provider}] timed out.", 'ai_provider_timeout', 504, ['provider' => $provider], $previous);
    }
}
