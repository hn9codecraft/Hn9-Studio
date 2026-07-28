<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Provider\ProviderSettingData;
use App\Models\AiProvider;
use App\Models\ProviderSetting;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends RepositoryInterface<AiProvider>
 */
interface ProviderRepositoryInterface extends RepositoryInterface
{
    /**
     * Find a provider by its unique slug, or null.
     *
     * @param  list<string>  $with
     */
    public function findBySlug(string $slug, array $with = []): ?AiProvider;

    /**
     * All active providers, highest priority first.
     *
     * @return Collection<int, AiProvider>
     */
    public function active(): Collection;

    /**
     * All active providers of a given category, highest priority first.
     *
     * @return Collection<int, AiProvider>
     */
    public function activeByCategory(string $category): Collection;

    /**
     * Create or update a provider configuration setting, keyed uniquely by
     * (provider, key, environment).
     */
    public function updateOrCreateSetting(ProviderSettingData $data): ProviderSetting;
}
