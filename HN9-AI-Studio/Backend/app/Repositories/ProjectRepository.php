<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Project;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends BaseRepository<Project>
 */
class ProjectRepository extends BaseRepository implements ProjectRepositoryInterface
{
    /**
     * @return Builder<Project>
     */
    protected function query(): Builder
    {
        return Project::query();
    }

    protected function filterable(): array
    {
        return ['status', 'type'];
    }

    public function paginateForUser(int $userId, int $perPage = 15, array $filters = [], array $with = []): LengthAwarePaginator
    {
        $query = $this->query()->with($with)->where('user_id', $userId);

        // Apply simple equality filters declared by the repository
        $query = $this->applyFilters($query, $filters);

        // Free-text search across name, description and uuid
        if (! empty($filters['search'])) {
            $term = (string) $filters['search'];
            $query->where(function (Builder $q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
                    ->orWhere('uuid', 'like', "%{$term}%");
            });
        }

        // Date filter (created_at)
        if (! empty($filters['date'])) {
            $query->whereDate('created_at', (string) $filters['date']);
        }

        // Sorting: configurable with safe whitelist
        $allowed = ['created_at', 'updated_at', 'name', 'status'];
        $sort = isset($filters['sort']) && in_array($filters['sort'], $allowed, true) ? $filters['sort'] : 'created_at';
        $order = isset($filters['order']) && strtolower((string) $filters['order']) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $order)->paginate($perPage);
    }

    public function slugExistsForUser(int $userId, string $slug, ?int $ignoreId = null): bool
    {
        return $this->query()
            ->where('user_id', $userId)
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
