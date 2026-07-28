<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\DTOs\ProviderCapabilityDTO;
use App\AI\DTOs\ProviderConfigDTO;
use App\AI\Registry\ProviderRegistration;
use App\AI\Support\Capability;
use Closure;

/**
 * In-memory registry of AI provider bindings for the current process. Providers
 * register a build closure plus their declared capabilities; nothing is
 * instantiated and no API is called on registration.
 *
 * This is the runtime provider registry for the App\AI layer — distinct from
 * the persistence-backed App\Contracts\Providers\ProviderRegistryInterface
 * introduced in Sprint 5.2, which is the database read model.
 */
interface ProviderRegistryInterface
{
    /**
     * Register (or replace) a provider binding.
     *
     * @param  Closure(ProviderConfigDTO): AIProviderInterface  $factory
     */
    public function register(
        string $key,
        Closure $factory,
        ProviderCapabilityDTO $capabilities,
        int $priority = 0,
        bool $enabled = true,
    ): void;

    public function has(string $key): bool;

    /**
     * Get a registration, or throw if it is not registered.
     */
    public function get(string $key): ProviderRegistration;

    /**
     * All registrations keyed by provider key.
     *
     * @return array<string, ProviderRegistration>
     */
    public function all(): array;

    /**
     * Only the enabled registrations, keyed by provider key.
     *
     * @return array<string, ProviderRegistration>
     */
    public function enabled(): array;

    public function enable(string $key): void;

    public function disable(string $key): void;

    /**
     * Set the default provider key.
     */
    public function setDefault(string $key): void;

    /**
     * The default provider key, or null when none is set.
     */
    public function defaultKey(): ?string;

    /**
     * Enabled provider keys that declare the given capability, highest priority
     * first.
     *
     * @return list<string>
     */
    public function forCapability(Capability $capability): array;
}
