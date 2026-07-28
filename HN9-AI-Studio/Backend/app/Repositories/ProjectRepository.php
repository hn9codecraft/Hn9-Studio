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
        return $this->applyFilters($this->query()->with($with)->where('user_id', $userId), $filters)
            ->latest('id')
            ->paginate($perPage);
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
