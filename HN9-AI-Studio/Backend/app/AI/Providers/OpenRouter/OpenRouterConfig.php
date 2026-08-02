<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenRouter;

use App\AI\DTOs\ProviderConfigDTO;
use App\AI\Exceptions\ProviderNotConfiguredException;
use App\AI\Support\ConfigNormalizer;

/**
 * Resolved, validated OpenRouter settings. Every value originates in
 * `config/ai.php` (and therefore the environment) — no credential, endpoint,
 * model identifier, header or price is hard-coded here.
 *
 * OpenRouter is an aggregator: a single credential and endpoint front many
 * upstream vendors, so the model list and the per-model metadata are the parts
 * that vary by deployment and are configured, never compiled in.
 */
final readonly class OpenRouterConfig
{
    public const KEY = 'openrouter';

    /**
     * @param  list<string>  $models  Namespaced model identifiers, e.g. `vendor/model`.
     * @param  array<string, string>  $headers  Additional headers sent with every request.
     * @param  array<string, array{input?: float|int, output?: float|int}>  $pricing
     * @param  array<string, array<string, mixed>>  $modelMetadata  Per-model metadata, keyed by model identifier.
     */
    public function __construct(
        public string $apiKey,
        public string $baseUrl,
        public ?string $defaultModel,
        public int $timeout,
        public int $maxRetries,
        public array $models,
        public ?string $httpReferer,
        public ?string $appName,
        public array $headers,
        public bool $supportsStreaming,
        public bool $supportsFunctionCalling,
        public bool $usageAccounting,
        public array $pricing,
        public array $modelMetadata,
    ) {}

    public static function fromProviderConfig(ProviderConfigDTO $config): self
    {
        $apiKey = ConfigNormalizer::nonEmptyString($config->option('api_key'));
        $baseUrl = ConfigNormalizer::nonEmptyString($config->baseUrl);

        if ($apiKey === null || $baseUrl === null) {
            throw ProviderNotConfiguredException::forKey(self::KEY);
        }

        $pricing = $config->option('pricing', []);
        $metadata = $config->option('model_metadata', []);

        return new self(
            apiKey: $apiKey,
            baseUrl: rtrim($baseUrl, '/'),
            defaultModel: ConfigNormalizer::nonEmptyString($config->defaultModel),
            timeout: $config->timeout,
            maxRetries: $config->maxRetries,
            models: ConfigNormalizer::stringList($config->option('models', [])),
            httpReferer: ConfigNormalizer::nonEmptyString($config->option('http_referer')),
            appName: ConfigNormalizer::nonEmptyString($config->option('app_name')),
            headers: ConfigNormalizer::stringMap($config->option('headers', [])),
            supportsStreaming: (bool) $config->option('supports_streaming', false),
            supportsFunctionCalling: (bool) $config->option('supports_function_calling', false),
            usageAccounting: (bool) $config->option('usage_accounting', false),
            pricing: is_array($pricing) ? $pricing : [],
            modelMetadata: self::metadataMap($metadata),
        );
    }

    /**
     * Headers sent with every request: OpenRouter's optional attribution headers
     * (`HTTP-Referer` and `X-Title`, which identify the calling application on
     * the vendor's dashboards) plus any additional configured headers, which are
     * applied last so a deployment can override the defaults.
     *
     * Authentication is added by the client so it can never be displaced here.
     *
     * @return array<string, string>
     */
    public function requestHeaders(): array
    {
        $attribution = array_filter([
            'HTTP-Referer' => $this->httpReferer,
            'X-Title' => $this->appName,
        ], static fn (?string $value): bool => $value !== null);

        return [...$attribution, ...$this->headers];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function metadataMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $metadata = [];

        foreach ($value as $model => $entry) {
            if (is_string($model) && $model !== '' && is_array($entry)) {
                $metadata[$model] = $entry;
            }
        }

        return $metadata;
    }
}
