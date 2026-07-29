<?php

declare(strict_types=1);

namespace App\AI\Providers\Claude;

use App\AI\DTOs\ProviderConfigDTO;
use App\AI\Exceptions\ProviderNotConfiguredException;

final readonly class ClaudeConfig
{
    /** @param list<string> $models @param array<string, array{input?: float|int, output?: float|int}> $pricing */
    public function __construct(public string $apiKey, public string $baseUrl, public string $version, public ?string $defaultModel, public int $timeout, public int $maxRetries, public array $models, public bool $supportsStreaming, public bool $supportsFunctionCalling, public array $pricing) {}

    public static function fromProviderConfig(ProviderConfigDTO $config): self
    {
        $key = $config->option('api_key');
        $version = $config->option('version');
        if (! is_string($key) || $key === '' || ! is_string($config->baseUrl) || $config->baseUrl === '' || ! is_string($version) || $version === '') {
            throw ProviderNotConfiguredException::forKey('claude');
        }
        $models = $config->option('models', []);
        $pricing = $config->option('pricing', []);

        return new self($key, rtrim($config->baseUrl, '/'), $version, $config->defaultModel, $config->timeout, $config->maxRetries, is_array($models) ? array_values(array_filter($models, 'is_string')) : [], (bool) $config->option('supports_streaming'), (bool) $config->option('supports_function_calling'), is_array($pricing) ? $pricing : []);
    }
}
