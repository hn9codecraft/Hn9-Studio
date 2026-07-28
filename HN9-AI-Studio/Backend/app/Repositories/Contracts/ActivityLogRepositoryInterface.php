<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<ActivityLog>
 */
interface ActivityLogRepositoryInterface extends RepositoryInterface
{
    /**
     * Recent activity entries for a subject model, newest first.
     *
     * @return Collection<int, ActivityLog>
     */
    public function forSubject(Model $subject, int $limit = 50): Collection;

    /**
     * Recent activity entries caused by a user, newest first.
     *
     * @return Collection<int, ActivityLog>
     */
    public function forUser(int $userId, int $limit = 50): Collection;
}
