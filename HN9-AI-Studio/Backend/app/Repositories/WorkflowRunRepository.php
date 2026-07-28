<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\WorkflowRun;
use App\Repositories\Contracts\WorkflowRunRepositoryInterface;
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
}
