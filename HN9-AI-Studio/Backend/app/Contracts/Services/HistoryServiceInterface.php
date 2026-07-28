<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Read/query facade over the domain activity history (audit trail). Writing is
 * performed via {@see ActivityLoggerInterface}; this
 * service exposes history for a subject or a user.
 */
interface HistoryServiceInterface
{
    /**
     * Recent history entries for a subject model.
     *
     * @return Collection<int, ActivityLog>
     */
    public function forSubject(Model $subject, int $limit = 50): Collection;

    /**
     * Recent history entries caused by a user.
     *
     * @return Collection<int, ActivityLog>
     */
    public function forUser(User $user, int $limit = 50): Collection;
}
