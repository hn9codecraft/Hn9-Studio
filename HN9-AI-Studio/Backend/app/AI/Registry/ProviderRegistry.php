<?php

declare(strict_types=1);

namespace App\AI\Registry;

use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\DTOs\ProviderCapabilityDTO;
use App\AI\Exceptions\ProviderNotRegisteredException;
use App\AI\Support\Capability;
use Closure;

/**
 * In-memory implementation of the AI provider registry. Bound as a singleton so
 * registrations made during application boot persist for the request/worker
 * lifetime. Holds bindings and metadata only — it instantiates no provider and
 * makes no external call.
 */
final class ProviderRegistry implements ProviderRegistryInterface
{
    /**
     * @var array<string, ProviderRegistration>
     */
    private array $registrations = [];

    private ?string $default = null;

    public function register(
        string $key,
        Closure $factory,
        ProviderCapabilityDTO $capabilities,
        int $priority = 0,
        bool $enabled = true,
    ): void {
        $this->registrations[$key] = new ProviderRegistration(
            key: $key,
            factory: $factory,
            capabilities: $capabilities,
            priority: $priority,
            enabled: $enabled,
        );

        if ($this->default === null && $enabled) {
            $this->default = $key;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->registrations[$key]);
    }

    public function get(string $key): ProviderRegistration
    {
        return $this->registrations[$key] ?? throw ProviderNotRegisteredException::forKey($key);
    }

    public function all(): array
    {
        return $this->registrations;
    }

    public function enabled(): array
    {
        return array_filter(
            $this->registrations,
            static fn (ProviderRegistration $registration): bool => $registration->isEnabled(),
        );
    }

    public function enable(string $key): void
    {
        $this->get($key)->enable();
    }

    public function disable(string $key): void
    {
        $this->get($key)->disable();
    }

    public function setDefault(string $key): void
    {
        // Ensure the key exists before accepting it as the default.
        $this->get($key);

        $this->default = $key;
    }

    public function defaultKey(): ?string
    {
        return $this->default;
    }

    public function forCapability(Capability $capability): array
    {
        $matches = array_filter(
            $this->enabled(),
            static fn (ProviderRegistration $registration): bool => $registration->capabilities()->supports($capability),
        );

        uasort(
            $matches,
            static fn (ProviderRegistration $a, ProviderRegistration $b): int => $b->priority() <=> $a->priority(),
        );

        return array_keys($matches);
    }
}
