<?php

declare(strict_types=1);

namespace App\Services;

use App\AI\Responses\UsageResponse;
use App\Contracts\Services\PromptServiceInterface;
use App\DTOs\Prompt\PromptExecutionData;
use App\Models\AgentExecution;
use App\Models\PromptExecution;
use App\Repositories\Contracts\ExecutionUsageRepositoryInterface;
use App\Repositories\Contracts\PromptExecutionRepositoryInterface;
use App\Repositories\Contracts\ProviderRepositoryInterface;
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
        private ProviderRepositoryInterface $providers,
        private ExecutionUsageRepositoryInterface $usage,
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

    public function recordProviderUsage(
        PromptExecution $execution,
        ?UsageResponse $usage,
        ?string $model = null,
        ?string $providerKey = null,
        ?int $latencyMs = null,
    ): PromptExecution {
        $attributes = [];

        if ($model !== null && $model !== '') {
            $attributes['model'] = $model;
        }

        if ($latencyMs !== null) {
            $attributes['latency_ms'] = $latencyMs;
        }

        if ($providerKey !== null && $providerKey !== '') {
            $provider = $this->providers->findBySlug($providerKey);
            if ($provider !== null) {
                $attributes['ai_provider_id'] = $provider->getKey();
            }
        }

        if ($usage !== null) {
            $attributes['prompt_tokens'] = $usage->promptTokens;
            $attributes['completion_tokens'] = $usage->completionTokens;
            $attributes['total_tokens'] = $usage->totalTokens;

            if ($usage->hasBilledCost()) {
                $attributes['cost'] = $usage->cost;
                $attributes['currency'] = $usage->currency;
                $attributes['cost_source'] = $usage->costSource;
            }
        }

        if ($attributes !== []) {
            $execution = $this->prompts->update($execution, $attributes);
        }

        $this->usage->rollupAgentExecution((int) $execution->agent_execution_id);

        return $execution;
    }
}
