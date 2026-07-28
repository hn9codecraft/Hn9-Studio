<?php

declare(strict_types=1);

namespace App\Contracts\Execution;

use Illuminate\Database\Eloquent\Model;

/**
 * Records the lifecycle state of an execution row (workflow run, agent
 * execution, prompt execution). This tracks *state transitions and timing* of
 * a record — it does not run anything. The execution engine is a later sprint.
 *
 * @template TModel of Model
 */
interface ExecutionTrackerInterface
{
    /**
     * Mark the execution as started (status → running, stamp started_at).
     *
     * @param  TModel  $execution
     * @return TModel
     */
    public function markStarted(Model $execution): Model;

    /**
     * Mark the execution as successfully completed (stamp finished_at,
     * compute duration).
     *
     * @param  TModel  $execution
     * @param  array<string, mixed>  $result
     * @return TModel
     */
    public function markCompleted(Model $execution, array $result = []): Model;

    /**
     * Mark the execution as failed with an error message.
     *
     * @param  TModel  $execution
     * @return TModel
     */
    public function markFailed(Model $execution, string $error): Model;
}
