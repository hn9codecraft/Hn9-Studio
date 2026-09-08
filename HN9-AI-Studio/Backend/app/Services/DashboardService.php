<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Services\DashboardServiceInterface;
use App\Enums\ImageStatus;
use App\Enums\ProjectAssetStatus;
use App\Enums\ScriptStatus;
use App\Enums\VideoStatus;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Contracts\ActivityLogRepositoryInterface;
use App\Repositories\Contracts\DashboardRepositoryInterface;
use App\Repositories\Contracts\ExecutionUsageRepositoryInterface;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Owner-scoped studio overview, analytics, usage and cost. Studio counts come
 * from content tables; usage and cost come from prompt_executions.
 */
final readonly class DashboardService implements DashboardServiceInterface
{
    public function __construct(
        private DashboardRepositoryInterface $dashboard,
        private ActivityLogRepositoryInterface $activityLogs,
        private ExecutionUsageRepositoryInterface $executions,
        private ProjectRepositoryInterface $projects,
    ) {}

    public function summary(User $user): array
    {
        $userId = $user->getKey();

        return [
            'projects' => $this->dashboard->projectCountsForUser($userId),
            'scripts' => $this->dashboard->scriptCountsForUser($userId),
            'images' => $this->dashboard->imageCountsForUser($userId),
            'videos' => $this->dashboard->videoCountsForUser($userId),
            'assets' => $this->dashboard->assetCountsForUser($userId),
            'recent_projects' => $this->dashboard->recentProjectsForUser($userId, 8),
            'recent_activity' => $this->activityLogs->recentStudioForOwnedProjects($userId, 15),
        ];
    }

    public function analytics(User $user, ?string $from = null, ?string $to = null): array
    {
        $userId = $user->getKey();
        $projects = $this->dashboard->projectCountsForUser($userId, $from, $to);
        $projects['timeline'] = $this->dashboard->projectCreationTimelineForUser($userId, $from, $to);

        return [
            'projects' => $projects,
            'content' => [
                'scripts' => $this->flattenStatusCounts(
                    $this->dashboard->scriptCountsForUser($userId, $from, $to),
                    ScriptStatus::values(),
                ),
                'images' => $this->flattenStatusCounts(
                    $this->dashboard->imageCountsForUser($userId, $from, $to),
                    ImageStatus::values(),
                ),
                'videos' => $this->flattenStatusCounts(
                    $this->dashboard->videoCountsForUser($userId, $from, $to),
                    VideoStatus::values(),
                ),
                'assets' => $this->flattenStatusCounts(
                    $this->dashboard->assetCountsForUser($userId, $from, $to),
                    ProjectAssetStatus::values(),
                ),
            ],
            'creation_timeline' => $this->dashboard->contentCreationTimelineForUser($userId, $from, $to),
            'activity' => $this->activityLogs->studioAnalyticsForOwnedProjects($userId, $from, $to),
            'project_productivity' => $this->dashboard->projectProductivityForUser($userId, $from, $to)
                ->map(fn (Project $project): array => [
                    'project' => [
                        'id' => $project->uuid,
                        'name' => $project->name,
                    ],
                    'scripts' => (int) $project->scripts_count,
                    'images' => (int) $project->images_count,
                    'videos' => (int) $project->videos_count,
                    'assets' => (int) $project->assets_count,
                    'total_items' => (int) $project->scripts_count
                        + (int) $project->images_count
                        + (int) $project->videos_count
                        + (int) $project->assets_count,
                ])
                ->values()
                ->all(),
        ];
    }

    public function usage(User $user, array $filters = []): array
    {
        return $this->executions->usageForUser($user->getKey(), $this->executionFilters($user, $filters));
    }

    public function costs(User $user, array $filters = []): array
    {
        return $this->executions->costsForUser($user->getKey(), $this->executionFilters($user, $filters));
    }

    /**
     * @param  array{from?: string|null, to?: string|null, project?: string|null, provider?: string|null}  $filters
     * @return array{from?: string|null, to?: string|null, project_id?: int|null, provider?: string|null}
     */
    private function executionFilters(User $user, array $filters): array
    {
        $resolved = [
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'provider' => $filters['provider'] ?? null,
        ];

        $projectUuid = $filters['project'] ?? null;
        if (is_string($projectUuid) && $projectUuid !== '') {
            $project = $this->projects->findByUuid($projectUuid);
            if ($project === null || (int) $project->user_id !== (int) $user->getKey()) {
                throw new NotFoundHttpException('Project not found.');
            }
            $resolved['project_id'] = (int) $project->getKey();
        }

        return $resolved;
    }

    /**
     * @param  array{total: int, by_status: array<string, int>}  $counts
     * @param  list<string>  $statuses
     * @return array<string, int>
     */
    private function flattenStatusCounts(array $counts, array $statuses): array
    {
        $flat = ['total' => $counts['total']];

        foreach ($statuses as $status) {
            $flat[$status] = (int) ($counts['by_status'][$status] ?? 0);
        }

        return $flat;
    }
}
