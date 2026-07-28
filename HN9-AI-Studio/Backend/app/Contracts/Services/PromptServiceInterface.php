<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\DTOs\Prompt\PromptExecutionData;
use App\Models\AgentExecution;
use App\Models\PromptExecution;
use Illuminate\Database\Eloquent\Collection;

/**
 * Manages prompt-execution *records* for an agent execution.
 *
 * IMPORTANT: this does not render templates or call any model. It records
 * which template/variables an execution will use. Prompt rendering and the
 * model call belong to a later sprint.
 */
interface PromptServiceInterface
{
    /**
     * All prompt executions for an agent execution (in order).
     *
     * @return Collection<int, PromptExecution>
     */
    public function forAgentExecution(AgentExecution $agentExecution): Collection;

    public function getByUuid(string $uuid): PromptExecution;

    /**
     * Create a prompt-execution record in its initial state.
     */
    public function record(PromptExecutionData $data): PromptExecution;
}
