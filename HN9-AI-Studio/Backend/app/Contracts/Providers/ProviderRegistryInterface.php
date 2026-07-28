<?php

declare(strict_types=1);

namespace App\Contracts\Providers;

use App\Models\AiProvider;
use Illuminate\Support\Collection;

/**
 * Read model over the AI provider registry. Resolves provider *definitions*
 * and their capabilities from the database.
 *
 * This is metadata only — it returns no provider clients and performs no
 * generation. Provider integrations arrive in a later sprint.
 */
interface ProviderRegistryInterface
{
    /**
     * Resolve an active provider by slug, or throw ProviderException.
     */
    public function get(string $slug): AiProvider;

    /**
     * Whether an active provider with the slug exists.
     */
    public function has(string $slug): bool;

    /**
     * All active providers, highest priority first.
     *
     * @return Collection<int, AiProvider>
     */
    public function all(): Collection;

    /**
     * Active providers for a capability category, highest priority first.
     *
     * @return Collection<int, AiProvider>
     */
    public function forCategory(string $category): Collection;

    /**
     * The highest-priority active provider for a category, or null.
     */
    public function default(string $category): ?AiProvider;
}
