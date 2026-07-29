<?php

declare(strict_types=1);

namespace App\AI\Providers\Gemini;

use App\AI\DTOs\ProviderConfigDTO;
use App\AI\Exceptions\ProviderNotConfiguredException;

/**
 * Resolved, validated Gemini settings. Every value originates in
 * `config/ai.php` (and therefore the environment) — no credential, endpoint,
 * API version, model identifier or price is hard-coded here.
 */
final readonly class GeminiConfig
{
    /**
     * @param  list<string>  $models  Generative text models.
     * @param  list<string>  $imageModels  Models permitted to return image output.
     * @param  list<string>  $imageResponseModalities  `generationConfig.responseModalities` for image requests.
     * @param  array<string, array{input?: float|int, output?: float|int}>  $pricing
     */
    public function __construct(
        public string $apiKey,
        public string $baseUrl,
        public string $version,
        public ?string $defaultModel,
        public int $timeout,
        public int $maxRetries,
        public array $models,
        public array $imageModels,
        public ?string $imageDefaultModel,
        public array $imageResponseModalities,
        public bool $remoteTokenCounting,
        public bool $supportsStreaming,
        public bool $supportsFunctionCalling,
        public array $pricing,
    ) {}

    public static function fromProviderConfig(ProviderConfigDTO $config): self
    {
        $apiKey = $config->option('api_key');
        $version = $config->option('version');
        $baseUrl = $config->baseUrl;

        if (! is_string($apiKey) || $apiKey === ''
            || ! is_string($baseUrl) || $baseUrl === ''
            || ! is_string($version) || trim($version, '/') === '') {
            throw ProviderNotConfiguredException::forKey('gemini');
        }

        $imageDefault = $config->option('image_default_model');
        $pricing = $config->option('pricing', []);

        return new self(
            apiKey: $apiKey,
            baseUrl: rtrim($baseUrl, '/'),
            version: trim($version, '/'),
            defaultModel: $config->defaultModel,
            timeout: $config->timeout,
            maxRetries: $config->maxRetries,
            models: self::stringList($config->option('models', [])),
            imageModels: self::stringList($config->option('image_models', [])),
            imageDefaultModel: is_string($imageDefault) && $imageDefault !== '' ? $imageDefault : null,
            imageResponseModalities: self::stringList($config->option('image_response_modalities', [])),
            remoteTokenCounting: (bool) $config->option('remote_token_counting', false),
            supportsStreaming: (bool) $config->option('supports_streaming', false),
            supportsFunctionCalling: (bool) $config->option('supports_function_calling', false),
            pricing: is_array($pricing) ? $pricing : [],
        );
    }

    /**
     * The versioned API root, e.g. `https://generativelanguage.googleapis.com/v1beta`.
     */
    public function endpoint(): string
    {
        return "{$this->baseUrl}/{$this->version}";
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
