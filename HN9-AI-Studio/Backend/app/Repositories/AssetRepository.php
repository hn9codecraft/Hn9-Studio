<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\GeneratedAsset;
use App\Repositories\Contracts\AssetRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    public function paginateForOwner(?int $userId, int $perPage = 15, array $filters = [], array $with = []): LengthAwarePaginator
    {
        $query = $this->query()->with($with);

        if ($userId !== null) {
            $query->whereHas('project', fn (Builder $project): Builder => $project->where('user_id', $userId));
        }

        $query = $this->applyFilters($query, $filters);

        if (! empty($filters['project']) || ! empty($filters['projectUuid'])) {
            $projectUuid = (string) ($filters['project'] ?? $filters['projectUuid'] ?? '');
            $query->whereHas('project', fn (Builder $project): Builder => $project->where('uuid', $projectUuid));
        }

        if (! empty($filters['provider'])) {
            $query->where('provider', (string) $filters['provider']);
        }

        if (! empty($filters['search'])) {
            $term = (string) $filters['search'];
            $query->where(function (Builder $q) use ($term): void {
                $q->where('prompt', 'like', "%{$term}%")
                    ->orWhere('uuid', 'like', "%{$term}%")
                    ->orWhere('type', 'like', "%{$term}%");
            });
        }

        if (isset($filters['favorite']) && $filters['favorite'] !== '') {
            $query->where('is_favorite', filter_var($filters['favorite'], FILTER_VALIDATE_BOOLEAN));
        }

        $allowed = ['created_at', 'updated_at', 'type', 'status'];
        $sort = isset($filters['sort']) && in_array($filters['sort'], $allowed, true) ? $filters['sort'] : 'created_at';
        $order = isset($filters['order']) && strtolower((string) $filters['order']) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $order)->paginate($perPage);
    }
}
