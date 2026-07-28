<?php

declare(strict_types=1);

namespace App\AI\Manager;

use App\AI\Contracts\AIProviderInterface;
use App\AI\Contracts\HealthManagerInterface;
use App\AI\Contracts\ProviderFactoryInterface;
use App\AI\Contracts\ProviderManagerInterface;
use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\DTOs\ProviderCapabilityDTO;
use App\AI\DTOs\ProviderHealthDTO;
use App\AI\Exceptions\ProviderDisabledException;
use App\AI\Exceptions\ProviderNotRegisteredException;
use App\AI\Support\Capability;

/**
 * Public entry point to the AI provider subsystem (Manager pattern). Coordinates
 * the registry, factory and health manager to resolve/validate providers, expose
 * capability discovery and aggregate health.
 *
 * It contains no provider-specific code and calls no external API — resolution
 * is delegated to the factory, which builds providers lazily on request.
 */
final readonly class ProviderManager implements ProviderManagerInterface
{
    public function __construct(
        private ProviderRegistryInterface $registry,
        private ProviderFactoryInterface $factory,
        private HealthManagerInterface $health,
    ) {}

    public function provider(string $key): AIProviderInterface
    {
        $this->validate($key);

        return $this->factory->make($key);
    }

    public function default(): AIProviderInterface
    {
        return $this->factory->makeDefault();
    }

    public function has(string $key): bool
    {
        return $this->registry->has($key) && $this->registry->get($key)->isEnabled();
    }

    public function validate(string $key): void
    {
        if (! $this->registry->has($key)) {
            throw ProviderNotRegisteredException::forKey($key);
        }

        if (! $this->registry->get($key)->isEnabled()) {
            throw ProviderDisabledException::forKey($key);
        }
    }

    public function available(): array
    {
        return array_keys($this->registry->enabled());
    }

    public function capabilities(string $key): ProviderCapabilityDTO
    {
        return $this->registry->get($key)->capabilities();
    }

    public function forCapability(Capability $capability): array
    {
        return $this->registry->forCapability($capability);
    }

    public function health(): array
    {
        return $this->health->aggregate();
    }

    public function healthOf(string $key): ProviderHealthDTO
    {
        return $this->health->check($key);
    }
}
