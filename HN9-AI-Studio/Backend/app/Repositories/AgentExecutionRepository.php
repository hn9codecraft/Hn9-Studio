<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\AgentExecution;
use App\Repositories\Contracts\AgentExecutionRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<AgentExecution>
 */
class AgentExecutionRepository extends BaseRepository implements AgentExecutionRepositoryInterface
{
    /**
     * @return Builder<AgentExecution>
     */
    protected function query(): Builder
    {
        return AgentExecution::query();
    }

    protected function filterable(): array
    {
        return ['status', 'agent_key'];
    }

    public function forWorkflowRun(int $workflowRunId, array $with = []): Collection
    {
        return $this->query()->with($with)->where('workflow_run_id', $workflowRunId)->orderBy('id')->get();
    }
}
