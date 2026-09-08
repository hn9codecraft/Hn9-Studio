<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

interface DashboardServiceInterface
{
    /**
     * Owner-scoped studio overview for the authenticated user.
     *
     * @return array{
     *     projects: array{total: int, draft: int, active: int, completed: int, archived: int},
     *     scripts: array{total: int, by_status: array<string, int>},
     *     images: array{total: int, by_status: array<string, int>},
     *     videos: array{total: int, by_status: array<string, int>},
     *     assets: array{total: int, by_status: array<string, int>},
     *     recent_projects: Collection<int, Project>,
     *     recent_activity: Collection<int, ActivityLog>
     * }
     */
    public function summary(User $user): array;

    /**
     * Owner-scoped studio analytics. Date bounds are inclusive calendar days.
     *
     * @return array<string, mixed>
     */
    public function analytics(User $user, ?string $from = null, ?string $to = null): array;

    /**
     * Owner-scoped prompt-execution usage. Missing tokens stay null.
     *
     * @param  array{from?: string|null, to?: string|null, project?: string|null, provider?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function usage(User $user, array $filters = []): array;

    /**
     * Owner-scoped recorded costs. Absent costs stay empty, not invented spend.
     *
     * @param  array{from?: string|null, to?: string|null, project?: string|null, provider?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function costs(User $user, array $filters = []): array;
}
