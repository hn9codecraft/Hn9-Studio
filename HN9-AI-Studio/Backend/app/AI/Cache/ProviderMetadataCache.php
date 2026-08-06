<?php

declare(strict_types=1);

namespace App\AI\Cache;

use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\DTOs\ProviderCapabilityDTO;
use App\AI\Support\Capability;

/**
 * Memoises the per-provider metadata routing consults on every request: the
 * declared capability set and the model catalogue behind it.
 *
 * Capabilities are declared once at registration, so this reads the registry
 * rather than instantiating anything — model-aware routing costs a hash lookup
 * instead of a provider build.
 */
final class ProviderMetadataCache
{
    /**
     * @var array<string, ProviderCapabilityDTO>
     */
    private array $capabilities = [];

    /**
     * Model identifiers held as a lookup map for O(1) membership tests.
     *
     * @var array<string, array<string, true>>
     */
    private array $models = [];

    public function __construct(private readonly ProviderRegistryInterface $registry) {}

    public function capabilities(string $key): ProviderCapabilityDTO
    {
        return $this->capabilities[$key] ??= $this->registry->get($key)->capabilities();
    }

    /**
     * Whether the provider declares the capability.
     */
    public function supports(string $key, Capability $capability): bool
    {
        return $this->capabilities($key)->supports($capability);
    }

    /**
     * Whether the provider exposes a model identifier. A provider that
     * publishes no catalogue is treated as unconstrained rather than as
     * supporting nothing — its own registry validates the model at call time.
     */
    public function exposesModel(string $key, string $model): bool
    {
        $models = $this->models[$key] ??= array_fill_keys($this->capabilities($key)->models, true);

        return $models === [] || isset($models[$model]);
    }

    /**
     * The provider's configured model catalogue.
     *
     * @return list<string>
     */
    public function models(string $key): array
    {
        return $this->capabilities($key)->models;
    }

    public function flush(): void
    {
        $this->capabilities = [];
        $this->models = [];
    }
}
