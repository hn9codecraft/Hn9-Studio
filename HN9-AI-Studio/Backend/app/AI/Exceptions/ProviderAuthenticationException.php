<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

final class ProviderAuthenticationException extends AIException
{
    public static function forProvider(string $provider): self
    {
        return new self("Authentication failed for AI provider [{$provider}].", 'ai_provider_authentication_failed', 401, ['provider' => $provider]);
    }
}
