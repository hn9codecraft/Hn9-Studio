<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\Models\ActivityLog;
use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read-only project-scoped studio activity. Writes stay on ActivityLogger.
 */
interface ProjectActivityServiceInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginateForProject(Project $project, int $perPage = 15, array $filters = []): LengthAwarePaginator;
}
