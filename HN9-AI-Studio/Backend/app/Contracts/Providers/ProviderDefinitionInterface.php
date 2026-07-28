<?php

declare(strict_types=1);

namespace App\Contracts\Providers;

use App\Enums\ProviderType;

/**
 * Shape of an AI provider *definition* — its identity and declared
 * capabilities. This is the contract a registry entry satisfies.
 *
 * Deliberately transport-free: it describes a provider, it does not talk to
 * one. Client/transport contracts belong to a later sprint.
 */
interface ProviderDefinitionInterface
{
    /**
     * Unique registry slug (e.g. "openai").
     */
    public function slug(): string;

    /**
     * Capability category.
     */
    public function type(): ProviderType;

    /**
     * Whether the provider declares the given capability.
     */
    public function supports(string $capability): bool;

    /**
     * The provider's declared capabilities.
     *
     * @return array<string, mixed>
     */
    public function capabilities(): array;
}
