<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Services\AgentExecutionServiceInterface;
use App\DTOs\Agent\AgentExecutionData;
use App\Models\AgentExecution;
use App\Models\WorkflowRun;
use App\Repositories\Contracts\AgentExecutionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Manages agent-execution records within a workflow run.
 *
 * IMPORTANT: no agent is invoked here — this creates and reads tracking rows
 * only. Agent execution belongs to a later sprint.
 */
final readonly class AgentExecutionService implements AgentExecutionServiceInterface
{
    public function __construct(
        private AgentExecutionRepositoryInterface $executions,
    ) {}

    public function forWorkflowRun(WorkflowRun $run): Collection
    {
        return $this->executions->forWorkflowRun($run->getKey());
    }

    public function getByUuid(string $uuid): AgentExecution
    {
        return $this->executions->findByUuidOrFail($uuid);
    }

    public function create(AgentExecutionData $data): AgentExecution
    {
        return $this->executions->create($data->toArray());
    }
}
