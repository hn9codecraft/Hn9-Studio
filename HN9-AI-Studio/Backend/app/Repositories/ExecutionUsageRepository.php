<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\AgentExecution;
use App\Models\PromptExecution;
use App\Repositories\Contracts\ExecutionUsageRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;

final class ExecutionUsageRepository implements ExecutionUsageRepositoryInterface
{
    public function usageForUser(int $userId, array $filters = []): array
    {
        $totals = $this->ownedPromptQuery($userId, $filters)
            ->selectRaw('COUNT(*) as operations')
            ->selectRaw('SUM(prompt_executions.prompt_tokens) as input_tokens')
            ->selectRaw('SUM(prompt_executions.completion_tokens) as output_tokens')
            ->selectRaw('SUM(prompt_executions.total_tokens) as total_tokens')
            ->selectRaw('SUM(CASE WHEN prompt_executions.prompt_tokens IS NOT NULL THEN 1 ELSE 0 END) as input_known')
            ->selectRaw('SUM(CASE WHEN prompt_executions.completion_tokens IS NOT NULL THEN 1 ELSE 0 END) as output_known')
            ->selectRaw('SUM(CASE WHEN prompt_executions.total_tokens IS NOT NULL THEN 1 ELSE 0 END) as total_known')
            ->first();

        $operations = (int) ($totals?->operations ?? 0);

        return [
            'operations' => $operations,
            'tokens' => [
                'input' => $this->knownSum($totals?->input_tokens, (int) ($totals?->input_known ?? 0)),
                'output' => $this->knownSum($totals?->output_tokens, (int) ($totals?->output_known ?? 0)),
                'total' => $this->knownSum($totals?->total_tokens, (int) ($totals?->total_known ?? 0)),
                'operations_with_tokens' => (int) ($totals?->total_known ?? 0),
                'operations_without_tokens' => max(0, $operations - (int) ($totals?->total_known ?? 0)),
            ],
            'by_provider' => $this->groupUsage($userId, $filters, 'ai_providers.slug', 'provider'),
            'by_model' => $this->groupUsage($userId, $filters, 'prompt_executions.model', 'model'),
            'timeline' => $this->usageTimeline($userId, $filters),
        ];
    }

    public function costsForUser(int $userId, array $filters = []): array
    {
        $priced = $this->ownedPromptQuery($userId, $filters)
            ->whereNotNull('prompt_executions.cost');

        $rows = (clone $priced)
            ->selectRaw('prompt_executions.currency as currency')
            ->selectRaw('COUNT(*) as operations')
            ->selectRaw('SUM(prompt_executions.cost) as amount')
            ->groupBy('prompt_executions.currency')
            ->get();

        $hasRecords = $rows->sum('operations') > 0;

        return [
            'has_records' => $hasRecords,
            'message' => $hasRecords
                ? null
                : 'Cost data is not available yet. No provider costs have been recorded.',
            'totals' => $rows->map(fn ($row): array => [
                'currency' => $row->currency ?: 'USD',
                'amount' => $this->decimal((string) $row->amount),
                'operations' => (int) $row->operations,
            ])->values()->all(),
            'by_provider' => $this->groupCosts($userId, $filters, 'ai_providers.slug', 'provider'),
            'by_model' => $this->groupCosts($userId, $filters, 'prompt_executions.model', 'model'),
            'timeline' => $this->costTimeline($userId, $filters),
        ];
    }

    public function rollupAgentExecution(int $agentExecutionId): void
    {
        $row = PromptExecution::query()
            ->where('agent_execution_id', $agentExecutionId)
            ->selectRaw('SUM(total_tokens) as tokens_used')
            ->selectRaw('SUM(CASE WHEN total_tokens IS NOT NULL THEN 1 ELSE 0 END) as token_rows')
            ->selectRaw('SUM(cost) as cost')
            ->selectRaw('SUM(CASE WHEN cost IS NOT NULL THEN 1 ELSE 0 END) as cost_rows')
            ->first();

        AgentExecution::query()->whereKey($agentExecutionId)->update([
            'tokens_used' => ((int) ($row?->token_rows ?? 0)) > 0 ? (int) $row->tokens_used : null,
            'cost' => ((int) ($row?->cost_rows ?? 0)) > 0 ? $row->cost : null,
        ]);
    }

    /**
     * @param  array{from?: string|null, to?: string|null, project_id?: int|null, provider?: string|null}  $filters
     * @return Builder<PromptExecution>
     */
    private function ownedPromptQuery(int $userId, array $filters): Builder
    {
        $query = PromptExecution::query()
            ->join('agent_executions', 'agent_executions.id', '=', 'prompt_executions.agent_execution_id')
            ->join('workflow_runs', 'workflow_runs.id', '=', 'agent_executions.workflow_run_id')
            ->join('projects', 'projects.id', '=', 'workflow_runs.project_id')
            ->leftJoin('ai_providers', 'ai_providers.id', '=', 'prompt_executions.ai_provider_id')
            ->where('projects.user_id', $userId)
            ->whereNull('projects.deleted_at')
            ->whereNull('workflow_runs.deleted_at')
            ->whereNull('agent_executions.deleted_at');

        if (! empty($filters['from'])) {
            $query->whereDate('prompt_executions.created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('prompt_executions.created_at', '<=', $filters['to']);
        }

        if (! empty($filters['project_id'])) {
            $query->where('projects.id', $filters['project_id']);
        }

        if (! empty($filters['provider'])) {
            $query->where('ai_providers.slug', $filters['provider']);
        }

        return $query;
    }

    /**
     * @param  array{from?: string|null, to?: string|null, project_id?: int|null, provider?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    private function groupUsage(int $userId, array $filters, string $column, string $label): array
    {
        return $this->ownedPromptQuery($userId, $filters)
            ->selectRaw("{$column} as grouping_key")
            ->selectRaw('COUNT(*) as operations')
            ->selectRaw('SUM(prompt_executions.prompt_tokens) as input_tokens')
            ->selectRaw('SUM(prompt_executions.completion_tokens) as output_tokens')
            ->selectRaw('SUM(prompt_executions.total_tokens) as total_tokens')
            ->selectRaw('SUM(CASE WHEN prompt_executions.total_tokens IS NOT NULL THEN 1 ELSE 0 END) as total_known')
            ->groupByRaw($column)
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->map(function ($row) use ($label): array {
                $known = (int) $row->total_known;

                return [
                    $label => $row->grouping_key,
                    'operations' => (int) $row->operations,
                    'input_tokens' => $this->knownSum($row->input_tokens, $known),
                    'output_tokens' => $this->knownSum($row->output_tokens, $known),
                    'total_tokens' => $this->knownSum($row->total_tokens, $known),
                ];
            })
            ->all();
    }

    /**
     * @param  array{from?: string|null, to?: string|null, project_id?: int|null, provider?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    private function groupCosts(int $userId, array $filters, string $column, string $label): array
    {
        return $this->ownedPromptQuery($userId, $filters)
            ->whereNotNull('prompt_executions.cost')
            ->selectRaw("{$column} as grouping_key")
            ->selectRaw('prompt_executions.currency as currency')
            ->selectRaw('COUNT(*) as operations')
            ->selectRaw('SUM(prompt_executions.cost) as amount')
            ->groupByRaw("{$column}, prompt_executions.currency")
            ->orderByRaw('SUM(prompt_executions.cost) DESC')
            ->get()
            ->map(fn ($row): array => [
                $label => $row->grouping_key,
                'currency' => $row->currency ?: 'USD',
                'amount' => $this->decimal((string) $row->amount),
                'operations' => (int) $row->operations,
            ])
            ->all();
    }

    /**
     * @param  array{from?: string|null, to?: string|null, project_id?: int|null, provider?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    private function usageTimeline(int $userId, array $filters): array
    {
        $day = $this->dayExpression('prompt_executions.created_at');

        return $this->ownedPromptQuery($userId, $filters)
            ->selectRaw("{$day} as day")
            ->selectRaw('COUNT(*) as operations')
            ->selectRaw('SUM(prompt_executions.prompt_tokens) as input_tokens')
            ->selectRaw('SUM(prompt_executions.completion_tokens) as output_tokens')
            ->selectRaw('SUM(prompt_executions.total_tokens) as total_tokens')
            ->selectRaw('SUM(CASE WHEN prompt_executions.total_tokens IS NOT NULL THEN 1 ELSE 0 END) as total_known')
            ->groupByRaw($day)
            ->orderByRaw($day)
            ->get()
            ->map(function ($row): array {
                $known = (int) $row->total_known;

                return [
                    'date' => (string) $row->day,
                    'operations' => (int) $row->operations,
                    'input_tokens' => $this->knownSum($row->input_tokens, $known),
                    'output_tokens' => $this->knownSum($row->output_tokens, $known),
                    'total_tokens' => $this->knownSum($row->total_tokens, $known),
                ];
            })
            ->all();
    }

    /**
     * @param  array{from?: string|null, to?: string|null, project_id?: int|null, provider?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    private function costTimeline(int $userId, array $filters): array
    {
        $day = $this->dayExpression('prompt_executions.created_at');

        return $this->ownedPromptQuery($userId, $filters)
            ->whereNotNull('prompt_executions.cost')
            ->selectRaw("{$day} as day")
            ->selectRaw('prompt_executions.currency as currency')
            ->selectRaw('COUNT(*) as operations')
            ->selectRaw('SUM(prompt_executions.cost) as amount')
            ->groupByRaw("{$day}, prompt_executions.currency")
            ->orderByRaw($day)
            ->get()
            ->map(fn ($row): array => [
                'date' => (string) $row->day,
                'currency' => $row->currency ?: 'USD',
                'amount' => $this->decimal((string) $row->amount),
                'operations' => (int) $row->operations,
            ])
            ->all();
    }

    private function dayExpression(string $column): string
    {
        $driver = PromptExecution::query()->getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return "date({$column})";
        }

        return "DATE({$column})";
    }

    private function knownSum(mixed $sum, int $knownCount): ?int
    {
        if ($knownCount < 1) {
            return null;
        }

        return (int) $sum;
    }

    private function decimal(string $amount): string
    {
        return number_format((float) $amount, 6, '.', '');
    }
}
