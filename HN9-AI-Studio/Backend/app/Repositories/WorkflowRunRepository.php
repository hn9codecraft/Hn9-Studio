<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\WorkflowRun;
use App\Repositories\Contracts\WorkflowRunRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<WorkflowRun>
 */
class WorkflowRunRepository extends BaseRepository implements WorkflowRunRepositoryInterface
{
    /**
     * @return Builder<WorkflowRun>
     */
    protected function query(): Builder
    {
        return WorkflowRun::query();
    }

    protected function filterable(): array
    {
        return ['status', 'workflow_key'];
    }

    public function forProject(int $projectId, array $with = []): Collection
    {
        return $this->query()->with($with)->where('project_id', $projectId)->latest('id')->get();
    }

    public function withStatus(string $status): Collection
    {
        return $this->query()->where('status', $status)->latest('id')->get();
    }

    public function paginateForOwner(?int $userId, int $perPage = 15, array $filters = [], array $with = []): LengthAwarePaginator
    {
        $query = $this->query()->with($with);

        if ($userId !== null) {
            $query->whereHas('project', fn (Builder $project): Builder => $project->where('user_id', $userId));
        }

        $query = $this->applyFilters($query, $filters);

        if (! empty($filters['project'])) {
            $query->whereHas('project', fn (Builder $project): Builder => $project->where('uuid', (string) $filters['project']));
        }

        if (! empty($filters['workflow'])) {
            $query->where('workflow_key', (string) $filters['workflow']);
        }

        if (! empty($filters['provider'])) {
            $query->where('context->provider', (string) $filters['provider']);
        }

        if (! empty($filters['created_from'])) {
            $query->whereDate('created_at', '>=', (string) $filters['created_from']);
        }

        if (! empty($filters['created_to'])) {
            $query->whereDate('created_at', '<=', (string) $filters['created_to']);
        }

        if (! empty($filters['search'])) {
            $term = (string) $filters['search'];
            $query->where(function (Builder $q) use ($term): void {
                $q->where('workflow_key', 'like', "%{$term}%")
                    ->orWhere('uuid', 'like', "%{$term}%")
                    ->orWhere('current_stage', 'like', "%{$term}%");
            });
        }

        $allowed = ['created_at', 'updated_at', 'started_at', 'finished_at', 'status'];
        $sort = isset($filters['sort']) && in_array($filters['sort'], $allowed, true) ? $filters['sort'] : 'started_at';
        $order = isset($filters['order']) && strtolower((string) $filters['order']) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $order)->paginate($perPage);
    }
}
