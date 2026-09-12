<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\ProjectActivityModule;
use App\Models\ActivityLog;
use App\Models\Image;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\Script;
use App\Models\Video;
use App\Repositories\Contracts\ActivityLogRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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

    public function paginateForProject(Project $project, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->query()->with($this->studioSubjectEagerLoad());

        $this->scopeToProjectStudio($query, $project);

        if (! empty($filters['module'])) {
            $module = ProjectActivityModule::tryFrom((string) $filters['module']);
            if ($module !== null) {
                $this->constrainActionToModule($query, $module);
            }
        }

        if (! empty($filters['action'])) {
            $query->where('action', (string) $filters['action']);
        }

        $order = isset($filters['order']) && strtolower((string) $filters['order']) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy('created_at', $order)->orderBy('id', $order)->paginate($perPage);
    }

    public function recentStudioForOwnedProjects(int $userId, int $limit = 15): Collection
    {
        return $this->ownedStudioQuery($userId)
            ->with($this->studioSubjectEagerLoad(withProject: true))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function studioAnalyticsForOwnedProjects(int $userId, ?string $from = null, ?string $to = null): array
    {
        $recentStart = now()->subDays(7)->startOfDay();

        $rows = $this->ownedStudioQueryForRange($userId, $from, $to)
            ->selectRaw(
                'action, COUNT(*) as aggregate, SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as recent_aggregate',
                [$recentStart],
            )
            ->groupBy('action')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        $byModule = [];
        foreach (ProjectActivityModule::cases() as $module) {
            $byModule[$module->value] = 0;
        }

        $byAction = [];
        $total = 0;
        $recentCount = 0;

        foreach ($rows as $row) {
            $action = (string) $row->action;
            $count = (int) $row->aggregate;
            $byAction[$action] = $count;
            $total += $count;
            $recentCount += (int) $row->recent_aggregate;

            $module = ProjectActivityModule::fromAction($action);
            if ($module !== null) {
                $byModule[$module->value] += $count;
            }
        }

        return [
            'total' => $total,
            'recent_count' => $recentCount,
            'by_module' => $byModule,
            'by_action' => $byAction,
        ];
    }

    /**
     * @return Builder<ActivityLog>
     */
    private function ownedStudioQueryForRange(int $userId, ?string $from, ?string $to): Builder
    {
        $query = $this->ownedStudioQuery($userId);
        $this->constrainCreatedAt($query, 'created_at', $from, $to);

        return $query;
    }

    /**
     * @return Builder<ActivityLog>
     */
    private function ownedStudioQuery(int $userId): Builder
    {
        $ownedProjectIds = Project::query()->where('user_id', $userId)->select('id');

        return $this->query()->where(function (Builder $outer) use ($ownedProjectIds): void {
            $this->constrainToOwnedProjectStudio($outer, $ownedProjectIds);
        });
    }

    /**
     * @param  Builder<ActivityLog>  $query
     */
    private function constrainCreatedAt(Builder $query, string $column, ?string $from, ?string $to): void
    {
        if ($from !== null && $from !== '') {
            $query->whereDate($column, '>=', $from);
        }

        if ($to !== null && $to !== '') {
            $query->whereDate($column, '<=', $to);
        }
    }

    /**
     * Limit rows to studio actions whose subject belongs to this project.
     *
     * @param  Builder<ActivityLog>  $query
     */
    private function scopeToProjectStudio(Builder $query, Project $project): void
    {
        $query->where(function (Builder $outer) use ($project): void {
            $this->constrainToOwnedProjectStudio($outer, $project->getKey());
        });
    }

    /**
     * @param  Builder<ActivityLog>  $query
     * @param  Builder<Project>|int  $ownedProjectIds
     */
    private function constrainToOwnedProjectStudio(Builder $query, Builder|int $ownedProjectIds): void
    {
        foreach (ProjectActivityModule::cases() as $module) {
            $query->orWhere(function (Builder $inner) use ($ownedProjectIds, $module): void {
                $inner->where('subject_type', (new ($module->subjectClass()))->getMorphClass());
                $this->constrainActionToModule($inner, $module);

                if ($module === ProjectActivityModule::Project) {
                    if (is_int($ownedProjectIds)) {
                        $inner->where('subject_id', $ownedProjectIds);

                        return;
                    }

                    $inner->whereIn('subject_id', $ownedProjectIds);

                    return;
                }

                $children = $module->subjectClass()::query()->withTrashed()->select('id');

                if (is_int($ownedProjectIds)) {
                    $children->where('project_id', $ownedProjectIds);
                } else {
                    $children->whereIn('project_id', $ownedProjectIds);
                }

                $inner->whereIn('subject_id', $children);
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function studioSubjectEagerLoad(bool $withProject = false): array
    {
        return [
            'user',
            'subject' => function (MorphTo $morphTo) use ($withProject): void {
                $withTrashed = static fn (Builder $builder): Builder => $builder->withTrashed();
                $withParent = static function (Builder $builder) use ($withProject): Builder {
                    $builder->withTrashed();

                    if ($withProject) {
                        $builder->with('project');
                    }

                    return $builder;
                };

                $morphTo->constrain([
                    Project::class => $withTrashed,
                    Script::class => $withParent,
                    Image::class => $withParent,
                    Video::class => $withParent,
                    ProjectAsset::class => $withParent,
                ]);
            },
        ];
    }

    /**
     * @param  Builder<ActivityLog>  $query
     */
    private function constrainActionToModule(Builder $query, ProjectActivityModule $module): void
    {
        if ($module === ProjectActivityModule::Project) {
            $query->where('action', 'like', 'project.%')
                ->where('action', 'not like', 'project_asset.%');

            return;
        }

        $query->where('action', 'like', $module->actionPrefix().'%');
    }
}
