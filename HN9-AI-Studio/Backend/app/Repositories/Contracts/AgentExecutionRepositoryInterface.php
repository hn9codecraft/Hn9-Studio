<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\AgentExecution;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends RepositoryInterface<AgentExecution>
 */
interface AgentExecutionRepositoryInterface extends RepositoryInterface
{
    /**
     * All agent executions for a workflow run, in creation order.
     *
     * @param  list<string>  $with
     * @return Collection<int, AgentExecution>
     */
    public function forWorkflowRun(int $workflowRunId, array $with = []): Collection;
}
