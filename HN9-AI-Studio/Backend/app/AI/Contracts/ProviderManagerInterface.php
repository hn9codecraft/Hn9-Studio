<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\DTOs\ProviderCapabilityDTO;
use App\AI\DTOs\ProviderHealthDTO;
use App\AI\Exceptions\ProviderDisabledException;
use App\AI\Exceptions\ProviderNotRegisteredException;
use App\AI\Support\Capability;

/**
 * The public entry point to the AI provider subsystem. Resolves and validates
 * providers, exposes capability discovery and aggregates health — delegating to
 * the registry, factory and health manager. It contains no provider-specific
 * code.
 */
interface ProviderManagerInterface
{
    /**
     * Resolve a provider instance by key.
     */
    public function provider(string $key): AIProviderInterface;

    /**
     * Resolve the configured default provider.
     */
    public function default(): AIProviderInterface;

    /**
     * Whether an enabled provider is registered under the key.
     */
    public function has(string $key): bool;

    /**
     * Assert a provider is registered and enabled.
     *
     * @throws ProviderNotRegisteredException
     * @throws ProviderDisabledException
     */
    public function validate(string $key): void;

    /**
     * Keys of all enabled (available) providers.
     *
     * @return list<string>
     */
    public function available(): array;

    /**
     * The declared capabilities of a provider.
     */
    public function capabilities(string $key): ProviderCapabilityDTO;

    /**
     * Enabled provider keys supporting the given capability, priority-ordered.
     *
     * @return list<string>
     */
    public function forCapability(Capability $capability): array;

    /**
     * Aggregate health across all enabled providers.
     *
     * @return array<string, ProviderHealthDTO>
     */
    public function health(): array;

    /**
     * Health of a single provider.
     */
    public function healthOf(string $key): ProviderHealthDTO;
}
