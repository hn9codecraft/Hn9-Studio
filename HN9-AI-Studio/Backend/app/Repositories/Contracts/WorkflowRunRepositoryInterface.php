<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\WorkflowRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends RepositoryInterface<WorkflowRun>
 */
interface WorkflowRunRepositoryInterface extends RepositoryInterface
{
    /**
     * All workflow runs for a project, newest first.
     *
     * @param  list<string>  $with
     * @return Collection<int, WorkflowRun>
     */
    public function forProject(int $projectId, array $with = []): Collection;

    /**
     * All runs currently in the given status.
     *
     * @return Collection<int, WorkflowRun>
     */
    public function withStatus(string $status): Collection;

    public function paginateForOwner(?int $userId, int $perPage = 15, array $filters = [], array $with = []): LengthAwarePaginator;
}
