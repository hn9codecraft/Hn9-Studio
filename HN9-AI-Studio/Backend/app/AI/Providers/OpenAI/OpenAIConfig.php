<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenAI;

use App\AI\DTOs\ProviderConfigDTO;
use App\AI\Exceptions\ProviderNotConfiguredException;

final readonly class OpenAIConfig
{
    /** @param list<string> $models @param array<string, array{input?: float|int, output?: float|int}> $pricing */
    public function __construct(
        public string $apiKey,
        public string $baseUrl,
        public ?string $defaultModel,
        public int $timeout,
        public int $maxRetries,
        public array $models,
        public bool $supportsStreaming,
        public bool $supportsFunctionCalling,
        public array $pricing,
    ) {}

    public static function fromProviderConfig(ProviderConfigDTO $config): self
    {
        $apiKey = $config->option('api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            throw ProviderNotConfiguredException::forKey('openai');
        }

        $models = $config->option('models', []);
        $pricing = $config->option('pricing', []);

        return new self(
            apiKey: $apiKey,
            baseUrl: rtrim($config->baseUrl ?? '', '/'),
            defaultModel: $config->defaultModel,
            timeout: $config->timeout,
            maxRetries: $config->maxRetries,
            models: is_array($models) ? array_values(array_filter($models, 'is_string')) : [],
            supportsStreaming: (bool) $config->option('supports_streaming', false),
            supportsFunctionCalling: (bool) $config->option('supports_function_calling', false),
            pricing: is_array($pricing) ? $pricing : [],
        );
    }
}
