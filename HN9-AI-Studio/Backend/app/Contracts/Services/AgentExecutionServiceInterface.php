<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\DTOs\Agent\AgentExecutionData;
use App\Models\AgentExecution;
use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Collection;

/**
 * Manages agent-execution *records* within a workflow run.
 *
 * IMPORTANT: no agent is invoked here. This creates tracking rows and records
 * their lifecycle only; agent execution belongs to a later sprint.
 */
interface AgentExecutionServiceInterface
{
    /**
     * All agent executions for a workflow run (in order).
     *
     * @return Collection<int, AgentExecution>
     */
    public function forWorkflowRun(WorkflowRun $run): Collection;

    public function getByUuid(string $uuid): AgentExecution;

    /**
     * Create an agent-execution record in its initial state.
     */
    public function create(AgentExecutionData $data): AgentExecution;
}
