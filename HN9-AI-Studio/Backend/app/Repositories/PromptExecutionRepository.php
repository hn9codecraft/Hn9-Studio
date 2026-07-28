<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\PromptExecution;
use App\Repositories\Contracts\PromptExecutionRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<PromptExecution>
 */
class PromptExecutionRepository extends BaseRepository implements PromptExecutionRepositoryInterface
{
    /**
     * @return Builder<PromptExecution>
     */
    protected function query(): Builder
    {
        return PromptExecution::query();
    }

    protected function filterable(): array
    {
        return ['status', 'template_key'];
    }

    public function forAgentExecution(int $agentExecutionId, array $with = []): Collection
    {
        return $this->query()->with($with)->where('agent_execution_id', $agentExecutionId)->orderBy('id')->get();
    }
}
