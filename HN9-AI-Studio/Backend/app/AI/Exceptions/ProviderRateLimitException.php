<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

final class ProviderRateLimitException extends AIException
{
    public static function forProvider(string $provider): self
    {
        return new self("Rate limit reached for AI provider [{$provider}].", 'ai_provider_rate_limited', 429, ['provider' => $provider]);
    }
}
