<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Services\PromptServiceInterface;
use App\DTOs\Prompt\PromptExecutionData;
use App\Models\AgentExecution;
use App\Models\PromptExecution;
use App\Repositories\Contracts\PromptExecutionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Manages prompt-execution records for an agent execution.
 *
 * IMPORTANT: this renders no templates and calls no model. It records which
 * template/variables an execution will use. Prompt rendering and the model
 * call belong to a later sprint.
 */
final readonly class PromptService implements PromptServiceInterface
{
    public function __construct(
        private PromptExecutionRepositoryInterface $prompts,
    ) {}

    public function forAgentExecution(AgentExecution $agentExecution): Collection
    {
        return $this->prompts->forAgentExecution($agentExecution->getKey());
    }

    public function getByUuid(string $uuid): PromptExecution
    {
        return $this->prompts->findByUuidOrFail($uuid);
    }

    public function record(PromptExecutionData $data): PromptExecution
    {
        return $this->prompts->create($data->toArray());
    }
}
