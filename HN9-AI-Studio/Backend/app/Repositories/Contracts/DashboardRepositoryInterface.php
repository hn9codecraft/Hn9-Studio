<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Project;
use Illuminate\Support\Collection;

interface DashboardRepositoryInterface
{
    /**
     * Owner-scoped project totals keyed by status.
     *
     * @return array{total: int, draft: int, active: int, completed: int, archived: int}
     */
    public function projectCountsForUser(int $userId, ?string $from = null, ?string $to = null): array;

    /**
     * @return array{total: int, by_status: array<string, int>}
     */
    public function scriptCountsForUser(int $userId, ?string $from = null, ?string $to = null): array;

    /**
     * @return array{total: int, by_status: array<string, int>}
     */
    public function imageCountsForUser(int $userId, ?string $from = null, ?string $to = null): array;

    /**
     * @return array{total: int, by_status: array<string, int>}
     */
    public function videoCountsForUser(int $userId, ?string $from = null, ?string $to = null): array;

    /**
     * @return array{total: int, by_status: array<string, int>}
     */
    public function assetCountsForUser(int $userId, ?string $from = null, ?string $to = null): array;

    /**
     * Recently updated projects owned by the user.
     *
     * @return Collection<int, Project>
     */
    public function recentProjectsForUser(int $userId, int $limit = 8): Collection;

    /**
     * Real project created_at counts by calendar day. Days without rows are omitted.
     *
     * @return list<array{date: string, count: int}>
     */
    public function projectCreationTimelineForUser(int $userId, ?string $from = null, ?string $to = null): array;

    /**
     * Real studio created_at counts by calendar day. Days without rows are omitted.
     *
     * @return list<array{date: string, projects: int, scripts: int, images: int, videos: int, assets: int}>
     */
    public function contentCreationTimelineForUser(int $userId, ?string $from = null, ?string $to = null): array;

    /**
     * Per-project studio item counts for owned, non-deleted projects.
     *
     * @return Collection<int, Project>
     */
    public function projectProductivityForUser(int $userId, ?string $from = null, ?string $to = null): Collection;

    /**
     * Owner-scoped studio rows that currently need attention.
     *
     * @param  array{module?: string|null, status?: string|null, project_id?: int|null}  $filters
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function attentionItemsForUser(int $userId, array $filters = []): array;
}
