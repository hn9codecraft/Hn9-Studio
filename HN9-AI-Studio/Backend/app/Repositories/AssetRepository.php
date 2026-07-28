<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\GeneratedAsset;
use App\Repositories\Contracts\AssetRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<GeneratedAsset>
 */
class AssetRepository extends BaseRepository implements AssetRepositoryInterface
{
    /**
     * @return Builder<GeneratedAsset>
     */
    protected function query(): Builder
    {
        return GeneratedAsset::query();
    }

    protected function filterable(): array
    {
        return ['type', 'status', 'provider'];
    }

    public function forProject(int $projectId, array $with = []): Collection
    {
        return $this->query()->with($with)->where('project_id', $projectId)->latest('id')->get();
    }

    public function forProjectOfType(int $projectId, string $type): Collection
    {
        return $this->query()
            ->where('project_id', $projectId)
            ->where('type', $type)
            ->latest('id')
            ->get();
    }
}
