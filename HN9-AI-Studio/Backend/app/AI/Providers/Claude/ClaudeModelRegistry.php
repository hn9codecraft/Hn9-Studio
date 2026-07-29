<?php

declare(strict_types=1);

namespace App\AI\Providers\Claude;

use App\AI\Exceptions\ProviderNotConfiguredException;

final readonly class ClaudeModelRegistry
{
    public function __construct(private ClaudeConfig $config) {}

    /** @return list<string> */
    public function all(): array
    {
        return $this->config->models;
    }

    public function resolve(?string $model): string
    {
        $resolved = $model ?? $this->config->defaultModel;
        if (! is_string($resolved) || $resolved === '' || ! in_array($resolved, $this->config->models, true)) {
            throw ProviderNotConfiguredException::forKey('claude');
        }

        return $resolved;
    }
}
