<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\PromptExecution;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends RepositoryInterface<PromptExecution>
 */
interface PromptExecutionRepositoryInterface extends RepositoryInterface
{
    /**
     * All prompt executions for an agent execution, in creation order.
     *
     * @param  list<string>  $with
     * @return Collection<int, PromptExecution>
     */
    public function forAgentExecution(int $agentExecutionId, array $with = []): Collection;
}
