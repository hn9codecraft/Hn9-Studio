<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

/**
 * Owner-scoped aggregations over prompt_executions via workflow_runs → projects.
 *
 * @phpstan-type LedgerFilters array{from?: string|null, to?: string|null, project_id?: int|null, provider?: string|null}
 */
interface ExecutionUsageRepositoryInterface
{
    /**
     * @param  LedgerFilters  $filters
     * @return array<string, mixed>
     */
    public function usageForUser(int $userId, array $filters = []): array;

    /**
     * @param  LedgerFilters  $filters
     * @return array<string, mixed>
     */
    public function costsForUser(int $userId, array $filters = []): array;

    /**
     * Roll up non-null token and cost totals onto the parent agent execution.
     */
    public function rollupAgentExecution(int $agentExecutionId): void;
}
