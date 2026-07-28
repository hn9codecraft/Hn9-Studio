<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Contracts\Execution\ExecutionTrackerInterface;
use App\Enums\ExecutionStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Read helpers for models that track an execution lifecycle with `status`,
 * `started_at`, `finished_at` and `duration_ms` columns (workflow runs, agent
 * executions).
 *
 * State transitions are applied by the execution-tracker services
 * ({@see ExecutionTrackerInterface}); this trait only
 * inspects the current state.
 *
 * @mixin Model
 */
trait TracksExecution
{
    /**
     * Whether the execution has begun (has a start timestamp).
     */
    public function hasStarted(): bool
    {
        return $this->getAttribute('started_at') !== null;
    }

    /**
     * Whether the execution is currently running.
     */
    public function isRunning(): bool
    {
        return (string) $this->getAttribute('status') === ExecutionStatus::Running->value;
    }

    /**
     * Whether the execution has reached a terminal state.
     */
    public function isFinished(): bool
    {
        return $this->getAttribute('finished_at') !== null;
    }

    /**
     * The execution duration in seconds (from `duration_ms`), or null.
     */
    public function durationSeconds(): ?float
    {
        $ms = $this->getAttribute('duration_ms');

        return $ms === null ? null : (int) $ms / 1000;
    }
}
