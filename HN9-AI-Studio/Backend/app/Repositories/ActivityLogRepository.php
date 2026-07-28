<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ActivityLog;
use App\Repositories\Contracts\ActivityLogRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<ActivityLog>
 */
class ActivityLogRepository extends BaseRepository implements ActivityLogRepositoryInterface
{
    /**
     * @return Builder<ActivityLog>
     */
    protected function query(): Builder
    {
        return ActivityLog::query();
    }

    protected function filterable(): array
    {
        return ['action', 'user_id'];
    }

    public function forSubject(Model $subject, int $limit = 50): Collection
    {
        return $this->query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function forUser(int $userId, int $limit = 50): Collection
    {
        return $this->query()
            ->where('user_id', $userId)
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
