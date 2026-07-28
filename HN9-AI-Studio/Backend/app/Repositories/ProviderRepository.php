<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\Provider\ProviderSettingData;
use App\Enums\Status;
use App\Models\AiProvider;
use App\Models\ProviderSetting;
use App\Repositories\Contracts\ProviderRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<AiProvider>
 */
class ProviderRepository extends BaseRepository implements ProviderRepositoryInterface
{
    /**
     * @return Builder<AiProvider>
     */
    protected function query(): Builder
    {
        return AiProvider::query();
    }

    protected function filterable(): array
    {
        return ['category', 'status'];
    }

    public function findBySlug(string $slug, array $with = []): ?AiProvider
    {
        return $this->query()->with($with)->where('slug', $slug)->first();
    }

    public function active(): Collection
    {
        return $this->query()
            ->where('status', Status::Active->value)
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();
    }

    public function activeByCategory(string $category): Collection
    {
        return $this->query()
            ->where('status', Status::Active->value)
            ->where('category', $category)
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();
    }

    public function updateOrCreateSetting(ProviderSettingData $data): ProviderSetting
    {
        return ProviderSetting::query()->updateOrCreate(
            [
                'ai_provider_id' => $data->ai_provider_id,
                'key' => $data->key,
                'environment' => $data->environment,
            ],
            [
                'value' => $data->value,
                'is_secret' => $data->is_secret,
            ],
        );
    }
}
