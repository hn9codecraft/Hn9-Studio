<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\DTOs\Workflow\WorkflowRunData;
use App\Enums\WorkflowStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Collection;

/**
 * Manages workflow-run *records* and their status transitions.
 *
 * IMPORTANT: this is not the pipeline execution engine. It creates and
 * transitions tracking rows only; nothing is executed. The engine, queue and
 * jobs belong to a later sprint.
 */
interface WorkflowServiceInterface
{
    /**
     * All workflow runs for a project (newest first).
     *
     * @return Collection<int, WorkflowRun>
     */
    public function forProject(Project $project): Collection;

    public function getByUuid(string $uuid): WorkflowRun;

    /**
     * Create a workflow-run record in its initial state.
     */
    public function create(WorkflowRunData $data, ?User $causer = null): WorkflowRun;

    /**
     * Transition a run to a new status, enforcing the lifecycle rules.
     */
    public function transition(WorkflowRun $run, WorkflowStatus $status, ?User $causer = null): WorkflowRun;
}
