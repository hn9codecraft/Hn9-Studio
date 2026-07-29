<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenAI;

use App\AI\Exceptions\ProviderNotConfiguredException;

final readonly class OpenAIModelRegistry
{
    public function __construct(private OpenAIConfig $config) {}

    /** @return list<string> */
    public function all(): array
    {
        return $this->config->models;
    }

    public function resolve(?string $model): string
    {
        $resolved = $model ?? $this->config->defaultModel;
        if (! is_string($resolved) || $resolved === '') {
            throw ProviderNotConfiguredException::forKey('openai');
        }

        if (! in_array($resolved, $this->config->models, true)) {
            throw ProviderNotConfiguredException::forKey('openai');
        }

        return $resolved;
    }
}
