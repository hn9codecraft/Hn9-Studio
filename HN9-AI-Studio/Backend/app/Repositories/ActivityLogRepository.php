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
        $query = $this->query()
            ->with([
                'user',
                'subject' => function (MorphTo $morphTo): void {
                    $withTrashed = static fn (Builder $builder): Builder => $builder->withTrashed();

                    $morphTo->constrain([
                        Project::class => $withTrashed,
                        Script::class => $withTrashed,
                        Image::class => $withTrashed,
                        Video::class => $withTrashed,
                        ProjectAsset::class => $withTrashed,
                    ]);
                },
            ]);

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

    /**
     * Limit rows to studio actions whose subject belongs to this project.
     *
     * @param  Builder<ActivityLog>  $query
     */
    private function scopeToProjectStudio(Builder $query, Project $project): void
    {
        $query->where(function (Builder $outer) use ($project): void {
            foreach (ProjectActivityModule::cases() as $module) {
                $outer->orWhere(function (Builder $inner) use ($project, $module): void {
                    $inner->where('subject_type', (new ($module->subjectClass()))->getMorphClass());
                    $this->constrainActionToModule($inner, $module);

                    if ($module === ProjectActivityModule::Project) {
                        $inner->where('subject_id', $project->getKey());

                        return;
                    }

                    $inner->whereIn(
                        'subject_id',
                        $module->subjectClass()::query()->withTrashed()->where('project_id', $project->getKey())->select('id'),
                    );
                });
            }
        });
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
