<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenRouter;

use App\AI\DTOs\ModelMetadataDTO;
use App\AI\Support\AbstractModelRegistry;
use App\AI\Support\Capability;
use App\AI\Support\ConfigNormalizer;

/**
 * OpenRouter model bookkeeping.
 *
 * OpenRouter's catalogue spans many upstream vendors and changes continuously,
 * so the exposed models are supplied entirely by configuration: no identifier,
 * vendor, context window or price is compiled in, and a model released after
 * this adapter was written is adopted by editing configuration alone.
 *
 * Per-model metadata is assembled from three sources, in order of precedence:
 * the explicit `model_metadata` block, the `pricing` block, and the identifier
 * itself (OpenRouter namespaces models as `vendor/model[:variant]`, which makes
 * the upstream vendor derivable rather than something to configure twice).
 */
final readonly class OpenRouterModelRegistry extends AbstractModelRegistry
{
    public function __construct(private OpenRouterConfig $config)
    {
        parent::__construct(OpenRouterConfig::KEY, $config->models, $config->defaultModel);
    }

    /**
     * Metadata for every configured model, keyed by model identifier.
     *
     * @return array<string, ModelMetadataDTO>
     */
    public function metadata(): array
    {
        $metadata = [];

        foreach ($this->models as $model) {
            $metadata[$model] = $this->metadataFor($model);
        }

        return $metadata;
    }

    public function metadataFor(string $model): ModelMetadataDTO
    {
        $declared = $this->config->modelMetadata[$model] ?? [];

        return new ModelMetadataDTO(
            id: $model,
            provider: $this->upstreamProvider($model),
            capabilities: $this->capabilities($declared),
            // Model level flags fall back to the provider level declaration.
            streaming: (bool) ($declared['streaming'] ?? $this->config->supportsStreaming),
            functionCalling: (bool) ($declared['function_calling'] ?? $this->config->supportsFunctionCalling),
            contextWindow: ConfigNormalizer::positiveInt($declared['context_window'] ?? null),
            maxOutputTokens: ConfigNormalizer::positiveInt($declared['max_output_tokens'] ?? null),
            pricing: $this->rates($model),
        );
    }

    /**
     * The upstream vendor serving a model: the configured value when given,
     * otherwise the namespace of the identifier.
     */
    public function upstreamProvider(string $model): ?string
    {
        $declared = ConfigNormalizer::nonEmptyString($this->config->modelMetadata[$model]['provider'] ?? null);

        if ($declared !== null) {
            return $declared;
        }

        $namespace = $this->catalogueRoute($model);

        return $namespace === null ? null : $namespace['author'];
    }

    /**
     * The distinct upstream vendors reachable through the configured models.
     *
     * @return list<string>
     */
    public function upstreamProviders(): array
    {
        $providers = [];

        foreach ($this->models as $model) {
            $provider = $this->upstreamProvider($model);

            if ($provider !== null) {
                $providers[] = $provider;
            }
        }

        return array_values(array_unique($providers));
    }

    /**
     * Split a namespaced identifier into the segments the catalogue route
     * addresses, or null when the identifier is not namespaced. A variant
     * suffix (`vendor/model:variant`) selects a routing tier of the same model,
     * so it is not part of the catalogue path.
     *
     * @return array{author: string, slug: string}|null
     */
    public function catalogueRoute(string $model): ?array
    {
        $segments = explode('/', $model, 2);

        if (count($segments) !== 2 || $segments[0] === '' || $segments[1] === '') {
            return null;
        }

        $slug = explode(':', $segments[1], 2)[0];

        return $slug === '' ? null : ['author' => $segments[0], 'slug' => $slug];
    }

    public function contextWindow(string $model): ?int
    {
        return $this->metadataFor($model)->contextWindow;
    }

    /**
     * The default model's context window, when configuration declares one.
     */
    public function defaultContextWindow(): ?int
    {
        return $this->defaultModel === null ? null : $this->contextWindow($this->defaultModel);
    }

    /**
     * Configured modalities for a model, defaulting to text — the modality every
     * model reachable through `chat/completions` answers.
     *
     * @param  array<string, mixed>  $declared
     * @return list<Capability>
     */
    private function capabilities(array $declared): array
    {
        $capabilities = [];

        foreach (ConfigNormalizer::stringList($declared['capabilities'] ?? []) as $capability) {
            $case = Capability::tryFrom($capability);

            if ($case !== null && ! in_array($case, $capabilities, true)) {
                $capabilities[] = $case;
            }
        }

        return $capabilities === [] ? [Capability::Text] : $capabilities;
    }

    /**
     * @return array<string, float>
     */
    private function rates(string $model): array
    {
        $rates = [];

        foreach ($this->config->pricing[$model] ?? [] as $direction => $rate) {
            $rates[$direction] = (float) $rate;
        }

        return $rates;
    }
}
