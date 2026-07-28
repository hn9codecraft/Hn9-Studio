<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\HistoryServiceInterface;
use App\Models\ActivityLog;
use App\Models\User;
use App\Repositories\Contracts\ActivityLogRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Read facade over the domain activity history (audit trail). Writes go through
 * {@see ActivityLoggerInterface}; this service only
 * queries history for a subject or a user.
 */
final readonly class HistoryService implements HistoryServiceInterface
{
    public function __construct(
        private ActivityLogRepositoryInterface $activityLogs,
    ) {}

    /**
     * @return Collection<int, ActivityLog>
     */
    public function forSubject(Model $subject, int $limit = 50): Collection
    {
        return $this->activityLogs->forSubject($subject, $limit);
    }

    /**
     * @return Collection<int, ActivityLog>
     */
    public function forUser(User $user, int $limit = 50): Collection
    {
        return $this->activityLogs->forUser($user->getKey(), $limit);
    }
}
