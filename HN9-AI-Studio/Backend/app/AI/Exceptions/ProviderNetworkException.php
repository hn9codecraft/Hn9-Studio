<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

final class ProviderNetworkException extends AIException
{
    public static function forProvider(string $provider, \Throwable $previous): self
    {
        return new self("Network failure while contacting AI provider [{$provider}].", 'ai_provider_network_failure', 503, ['provider' => $provider], $previous);
    }
}
