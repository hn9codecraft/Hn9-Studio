<?php

declare(strict_types=1);

namespace App\Services\Execution;

use App\Contracts\Execution\ExecutionTrackerInterface;
use App\Enums\ExecutionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Records lifecycle state transitions and timing on execution rows (workflow
 * runs, agent executions). It only stamps status/timestamps on a record — it
 * runs nothing. The execution engine that would call this arrives in a later
 * sprint.
 *
 * @implements ExecutionTrackerInterface<Model>
 */
final class ExecutionTracker implements ExecutionTrackerInterface
{
    public function markStarted(Model $execution): Model
    {
        $execution->forceFill([
            'status' => ExecutionStatus::Running->value,
            'started_at' => Carbon::now(),
        ])->save();

        return $execution;
    }

    public function markCompleted(Model $execution, array $result = []): Model
    {
        $finishedAt = Carbon::now();

        $execution->forceFill([
            'status' => ExecutionStatus::Completed->value,
            'finished_at' => $finishedAt,
            'duration_ms' => $this->durationMs($execution, $finishedAt),
        ])->save();

        return $execution;
    }

    public function markFailed(Model $execution, string $error): Model
    {
        $finishedAt = Carbon::now();

        $execution->forceFill([
            'status' => ExecutionStatus::Failed->value,
            'error' => $error,
            'finished_at' => $finishedAt,
            'duration_ms' => $this->durationMs($execution, $finishedAt),
        ])->save();

        return $execution;
    }

    /**
     * Elapsed milliseconds since the record's `started_at`, or null.
     */
    private function durationMs(Model $execution, Carbon $finishedAt): ?int
    {
        $startedAt = $execution->getAttribute('started_at');

        if (! $startedAt instanceof Carbon) {
            return null;
        }

        return (int) $startedAt->diffInMilliseconds($finishedAt);
    }
}
