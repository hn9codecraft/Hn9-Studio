<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Lifecycle of a workflow run. Mirrors the `status` column on the
 * `workflow_runs` table (default `pending`). This enum models the *record's*
 * state only — the execution engine that drives these transitions is a later
 * sprint.
 */
enum WorkflowStatus: string
{
    use InteractsWithEnum;

    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Whether the run has reached a terminal state.
     */
    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    /**
     * Whether the run is currently active (not yet terminal).
     */
    public function isActive(): bool
    {
        return ! $this->isFinished();
    }
}
