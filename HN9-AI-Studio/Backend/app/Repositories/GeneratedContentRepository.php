<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\GeneratedContent;
use App\Repositories\Contracts\GeneratedContentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<GeneratedContent>
 */
class GeneratedContentRepository extends BaseRepository implements GeneratedContentRepositoryInterface
{
    /**
     * @return Builder<GeneratedContent>
     */
    protected function query(): Builder
    {
        return GeneratedContent::query();
    }

    protected function filterable(): array
    {
        return ['type', 'status', 'channel', 'language'];
    }

    public function paginateForOwner(?int $userId, int $perPage = 15, array $filters = [], array $with = []): LengthAwarePaginator
    {
        $query = $this->query()->with($with);

        // Content has no owner of its own; ownership is the owning project's.
        if ($userId !== null) {
            $query->whereHas('project', fn (Builder $project): Builder => $project->where('user_id', $userId));
        }

        // Whitelisted equality filters declared by the repository (type, status,
        // channel, language).
        $query = $this->applyFilters($query, $filters);

        if (! empty($filters['project'])) {
            $query->whereHas('project', fn (Builder $project): Builder => $project->where('uuid', (string) $filters['project']));
        }

        // Provider and template are not columns: the orchestrator records them
        // inside the metadata/structured payloads, so they are filtered there.
        if (! empty($filters['provider'])) {
            $query->where('metadata->provider', (string) $filters['provider']);
        }

        if (! empty($filters['template'])) {
            $query->where('structured->template_key', (string) $filters['template']);
        }

        if (isset($filters['favorite']) && $filters['favorite'] !== '') {
            $query->where('is_favorite', filter_var($filters['favorite'], FILTER_VALIDATE_BOOLEAN));
        }

        // Free-text search across title, body and uuid
        if (! empty($filters['search'])) {
            $term = (string) $filters['search'];
            $query->where(function (Builder $q) use ($term): void {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('body', 'like', "%{$term}%")
                    ->orWhere('uuid', 'like', "%{$term}%");
            });
        }

        // Date filter (created_at)
        if (! empty($filters['date'])) {
            $query->whereDate('created_at', (string) $filters['date']);
        }

        // Sorting: configurable with safe whitelist
        $allowed = ['created_at', 'updated_at', 'title', 'status', 'type', 'version'];
        $sort = isset($filters['sort']) && in_array($filters['sort'], $allowed, true) ? $filters['sort'] : 'created_at';
        $order = isset($filters['order']) && strtolower((string) $filters['order']) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $order)->paginate($perPage);
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

    public function latestVersion(int $projectId, string $type): int
    {
        return (int) $this->query()
            ->where('project_id', $projectId)
            ->where('type', $type)
            ->max('version');
    }
}
