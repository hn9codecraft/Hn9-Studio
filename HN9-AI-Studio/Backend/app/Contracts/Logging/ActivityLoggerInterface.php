<?php

declare(strict_types=1);

namespace App\Contracts\Logging;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Records domain audit events into the append-only activity log. Services
 * depend on this contract to record "who did what to which record" without
 * knowing how or where the entry is persisted.
 */
interface ActivityLoggerInterface
{
    /**
     * Record an activity entry.
     *
     * @param  array<string, mixed>  $properties
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?User $causer = null,
        ?string $description = null,
        array $properties = [],
    ): ActivityLog;
}
