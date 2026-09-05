<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ProjectAsset;
use App\Repositories\Contracts\ProjectAssetRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends BaseRepository<ProjectAsset>
 */
class ProjectAssetRepository extends BaseRepository implements ProjectAssetRepositoryInterface
{
    /**
     * @return Builder<ProjectAsset>
     */
    protected function query(): Builder
    {
        return ProjectAsset::query();
    }

    protected function filterable(): array
    {
        return ['status', 'type', 'source'];
    }

    public function paginateForProject(int $projectId, int $perPage = 15, array $filters = [], array $with = []): LengthAwarePaginator
    {
        $query = $this->applyFilters($this->query()->with($with)->where('project_id', $projectId), $filters);

        $allowed = ['created_at', 'updated_at', 'title', 'status', 'type'];
        $sort = isset($filters['sort']) && in_array($filters['sort'], $allowed, true) ? $filters['sort'] : 'updated_at';
        $order = isset($filters['order']) && strtolower((string) $filters['order']) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $order)->paginate($perPage);
    }
}
