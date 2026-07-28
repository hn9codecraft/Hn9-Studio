<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Lifecycle shared by agent executions and prompt executions. Mirrors the
 * `status` column on `agent_executions` / `prompt_executions` (default
 * `pending`). Models the record's state only — no execution behaviour.
 */
enum ExecutionStatus: string
{
    use InteractsWithEnum;

    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Retrying = 'retrying';
    case Cancelled = 'cancelled';

    /**
     * Whether the execution has reached a terminal state.
     */
    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    /**
     * Whether the execution ended successfully.
     */
    public function isSuccessful(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Whether the execution may be retried.
     */
    public function isRetryable(): bool
    {
        return in_array($this, [self::Failed, self::Retrying], true);
    }
}
