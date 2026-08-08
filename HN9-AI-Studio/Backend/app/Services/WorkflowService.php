<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\WorkflowServiceInterface;
use App\DTOs\Workflow\WorkflowRunData;
use App\Enums\WorkflowStatus;
use App\Exceptions\WorkflowException;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Repositories\Contracts\WorkflowRunRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Manages workflow-run records and their status lifecycle.
 *
 * IMPORTANT: not the pipeline execution engine. It creates and transitions
 * tracking rows only; nothing is executed here. Queue, jobs and the engine
 * belong to a later sprint.
 */
final readonly class WorkflowService implements WorkflowServiceInterface
{
    public function __construct(
        private WorkflowRunRepositoryInterface $runs,
        private ActivityLoggerInterface $activity,
    ) {}

    public function forProject(Project $project): Collection
    {
        return $this->runs->forProject($project->getKey());
    }

    public function paginateForUser(User $user, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return $this->runs->paginateForOwner(
            $user->isAdmin() ? null : $user->getKey(),
            $perPage,
            $filters,
            ['project'],
        );
    }

    public function getByUuid(string $uuid): WorkflowRun
    {
        return $this->runs->findByUuidOrFail($uuid);
    }

    public function create(WorkflowRunData $data, ?User $causer = null): WorkflowRun
    {
        $run = $this->runs->create($data->toArray());

        $this->activity->log('workflow.created', $run, $causer, 'Workflow run created');

        return $run;
    }

    public function transition(WorkflowRun $run, WorkflowStatus $status, ?User $causer = null): WorkflowRun
    {
        $current = WorkflowStatus::tryFrom((string) $run->status) ?? WorkflowStatus::Pending;

        if ($current->isFinished()) {
            throw WorkflowException::alreadyFinished($current);
        }

        $run = $this->runs->update($run, ['status' => $status->value]);

        $this->activity->log('workflow.transitioned', $run, $causer, "Workflow status changed to {$status->value}");

        return $run;
    }

    public function retry(WorkflowRun $run, ?User $causer = null): WorkflowRun
    {
        $current = WorkflowStatus::tryFrom((string) $run->status) ?? WorkflowStatus::Pending;

        if ($current !== WorkflowStatus::Failed) {
            throw WorkflowException::invalidTransition($current, WorkflowStatus::Queued);
        }

        $run = $this->runs->update($run, ['status' => WorkflowStatus::Queued->value]);

        $this->activity->log('workflow.retried', $run, $causer, 'Workflow run retried');

        return $run;
    }

    public function cancel(WorkflowRun $run, ?User $causer = null): WorkflowRun
    {
        $current = WorkflowStatus::tryFrom((string) $run->status) ?? WorkflowStatus::Pending;

        if ($current->isFinished() || $current === WorkflowStatus::Cancelled) {
            throw WorkflowException::alreadyFinished($current);
        }

        $run = $this->runs->update($run, ['status' => WorkflowStatus::Cancelled->value]);

        $this->activity->log('workflow.cancelled', $run, $causer, 'Workflow run cancelled');

        return $run;
    }
}
