<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\ActivityLog;
use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    /**
     * Studio activity for one project (project + nested scripts/images/videos/assets).
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginateForProject(Project $project, int $perPage = 15, array $filters = []): LengthAwarePaginator;

    /**
     * Latest studio activity whose subject belongs to the user's non-deleted projects.
     *
     * @return Collection<int, ActivityLog>
     */
    public function recentStudioForOwnedProjects(int $userId, int $limit = 15): Collection;

    /**
     * Owner-scoped studio activity aggregations. Days/actions without rows are omitted from maps
     * that are derived from the database; module keys are always present with real zeros.
     *
     * @return array{
     *     total: int,
     *     recent_count: int,
     *     by_module: array<string, int>,
     *     by_action: array<string, int>
     * }
     */
    public function studioAnalyticsForOwnedProjects(int $userId, ?string $from = null, ?string $to = null): array;
}
