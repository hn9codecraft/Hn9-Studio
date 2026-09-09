<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\ImageStatus;
use App\Enums\ProjectAssetStatus;
use App\Enums\ProjectStatus;
use App\Enums\ScriptStatus;
use App\Enums\VideoStatus;
use App\Models\Image;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\Script;
use App\Models\Video;
use App\Repositories\Contracts\DashboardRepositoryInterface;
use App\Support\DashboardActionRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class DashboardRepository implements DashboardRepositoryInterface
{
    public function projectCountsForUser(int $userId, ?string $from = null, ?string $to = null): array
    {
        $query = Project::query()->where('user_id', $userId);
        $this->constrainCreatedAt($query, 'created_at', $from, $to);

        $rows = $query
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $byStatus = $this->fillStatusCounts(ProjectStatus::values(), $rows);

        return [
            'total' => (int) $rows->sum(),
            'draft' => $byStatus['draft'],
            'active' => $byStatus['active'],
            'completed' => $byStatus['completed'],
            'archived' => $byStatus['archived'],
        ];
    }

    public function scriptCountsForUser(int $userId, ?string $from = null, ?string $to = null): array
    {
        return $this->ownedChildStatusCounts(Script::class, $userId, ScriptStatus::values(), $from, $to);
    }

    public function imageCountsForUser(int $userId, ?string $from = null, ?string $to = null): array
    {
        return $this->ownedChildStatusCounts(Image::class, $userId, ImageStatus::values(), $from, $to);
    }

    public function videoCountsForUser(int $userId, ?string $from = null, ?string $to = null): array
    {
        return $this->ownedChildStatusCounts(Video::class, $userId, VideoStatus::values(), $from, $to);
    }

    public function assetCountsForUser(int $userId, ?string $from = null, ?string $to = null): array
    {
        return $this->ownedChildStatusCounts(ProjectAsset::class, $userId, ProjectAssetStatus::values(), $from, $to);
    }

    public function recentProjectsForUser(int $userId, int $limit = 8): Collection
    {
        return Project::query()
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function projectCreationTimelineForUser(int $userId, ?string $from = null, ?string $to = null): array
    {
        $query = Project::query()->where('user_id', $userId);
        $this->constrainCreatedAt($query, 'created_at', $from, $to);

        return $this->dailyCounts($query, 'created_at')
            ->map(fn (int $count, string $date): array => [
                'date' => $date,
                'count' => $count,
            ])
            ->values()
            ->all();
    }

    public function contentCreationTimelineForUser(int $userId, ?string $from = null, ?string $to = null): array
    {
        $projects = $this->dailyCounts(
            $this->ownedProjectQuery($userId, $from, $to),
            'created_at',
        );
        $scripts = $this->dailyCounts($this->ownedChildQuery(Script::class, $userId, $from, $to), 'scripts.created_at');
        $images = $this->dailyCounts($this->ownedChildQuery(Image::class, $userId, $from, $to), 'images.created_at');
        $videos = $this->dailyCounts($this->ownedChildQuery(Video::class, $userId, $from, $to), 'videos.created_at');
        $assets = $this->dailyCounts($this->ownedChildQuery(ProjectAsset::class, $userId, $from, $to), 'project_assets.created_at');

        $dates = $projects->keys()
            ->merge($scripts->keys())
            ->merge($images->keys())
            ->merge($videos->keys())
            ->merge($assets->keys())
            ->unique()
            ->sort()
            ->values();

        return $dates->map(fn (string $date): array => [
            'date' => $date,
            'projects' => (int) ($projects[$date] ?? 0),
            'scripts' => (int) ($scripts[$date] ?? 0),
            'images' => (int) ($images[$date] ?? 0),
            'videos' => (int) ($videos[$date] ?? 0),
            'assets' => (int) ($assets[$date] ?? 0),
        ])->all();
    }

    public function projectProductivityForUser(int $userId, ?string $from = null, ?string $to = null): Collection
    {
        $constrain = function (Builder $query) use ($from, $to): void {
            $this->constrainCreatedAt($query, $query->getModel()->getTable().'.created_at', $from, $to);
        };

        return Project::query()
            ->where('user_id', $userId)
            ->withCount([
                'scripts' => $constrain,
                'images' => $constrain,
                'videos' => $constrain,
                'assets' => $constrain,
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }

    public function attentionItemsForUser(int $userId, array $filters = []): array
    {
        $module = $filters['module'] ?? null;
        $status = $filters['status'] ?? null;
        $projectId = $filters['project_id'] ?? null;
        $limit = DashboardActionRules::LIMIT;

        $sources = $this->attentionSources($module, $status);
        $rows = [];
        $total = 0;

        foreach ($sources as $source) {
            $query = match ($source['query']) {
                'attentionImagesQuery' => $this->attentionImagesQuery($userId, $source['statuses'], $projectId),
                'attentionVideosQuery' => $this->attentionVideosQuery($userId, $source['statuses'], $projectId),
                'attentionProjectsQuery' => $this->attentionProjectsQuery($userId, $source['statuses'], $projectId),
                'attentionScriptsQuery' => $this->attentionScriptsQuery($userId, $source['statuses'], $projectId),
                'attentionAssetsQuery' => $this->attentionAssetsQuery($userId, $source['statuses'], $projectId),
            };
            $total += (clone $query)->count();
            $fetched = $query
                ->orderByDesc($source['created_at'])
                ->limit($limit)
                ->get();

            foreach ($fetched as $row) {
                $rows[] = [
                    'id' => (string) $row->item_uuid,
                    'module' => $source['module'],
                    'status' => (string) $row->item_status,
                    'title' => (string) $row->item_title,
                    'project_uuid' => (string) $row->project_uuid,
                    'project_name' => (string) $row->project_name,
                    'created_at' => $row->item_created_at,
                ];
            }
        }

        return [
            'total' => $total,
            'rows' => $rows,
        ];
    }

    /**
     * @return list<array{module: string, statuses: list<string>, created_at: string, query: string}>
     */
    private function attentionSources(?string $module, ?string $status): array
    {
        $imageStatuses = $this->intersectStatuses(
            [ImageStatus::Failed->value, ImageStatus::Pending->value, ImageStatus::Processing->value, ImageStatus::Draft->value],
            $status,
        );
        $videoStatuses = $this->intersectStatuses(
            [VideoStatus::Failed->value, VideoStatus::Pending->value, VideoStatus::Processing->value, VideoStatus::Draft->value],
            $status,
        );
        $draftOnly = $this->intersectStatuses([ProjectStatus::Draft->value], $status);

        $sources = [
            [
                'module' => 'image',
                'statuses' => $imageStatuses,
                'created_at' => 'images.created_at',
                'query' => 'attentionImagesQuery',
            ],
            [
                'module' => 'video',
                'statuses' => $videoStatuses,
                'created_at' => 'videos.created_at',
                'query' => 'attentionVideosQuery',
            ],
            [
                'module' => 'project',
                'statuses' => $draftOnly,
                'created_at' => 'projects.created_at',
                'query' => 'attentionProjectsQuery',
            ],
            [
                'module' => 'script',
                'statuses' => $draftOnly,
                'created_at' => 'scripts.created_at',
                'query' => 'attentionScriptsQuery',
            ],
            [
                'module' => 'asset',
                'statuses' => $draftOnly,
                'created_at' => 'project_assets.created_at',
                'query' => 'attentionAssetsQuery',
            ],
        ];

        return array_values(array_filter(
            $sources,
            function (array $source) use ($module): bool {
                if ($source['statuses'] === []) {
                    return false;
                }

                return $module === null || $module === '' || $source['module'] === $module;
            },
        ));
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function intersectStatuses(array $allowed, ?string $status): array
    {
        if ($status === null || $status === '') {
            return $allowed;
        }

        return in_array($status, $allowed, true) ? [$status] : [];
    }

    /**
     * @param  list<string>  $statuses
     * @return Builder<Image>
     */
    private function attentionImagesQuery(int $userId, array $statuses, ?int $projectId): Builder
    {
        return $this->attentionChildQuery(Image::class, $userId, $statuses, $projectId);
    }

    /**
     * @param  list<string>  $statuses
     * @return Builder<Video>
     */
    private function attentionVideosQuery(int $userId, array $statuses, ?int $projectId): Builder
    {
        return $this->attentionChildQuery(Video::class, $userId, $statuses, $projectId);
    }

    /**
     * @param  list<string>  $statuses
     * @return Builder<Script>
     */
    private function attentionScriptsQuery(int $userId, array $statuses, ?int $projectId): Builder
    {
        return $this->attentionChildQuery(Script::class, $userId, $statuses, $projectId);
    }

    /**
     * @param  list<string>  $statuses
     * @return Builder<ProjectAsset>
     */
    private function attentionAssetsQuery(int $userId, array $statuses, ?int $projectId): Builder
    {
        return $this->attentionChildQuery(ProjectAsset::class, $userId, $statuses, $projectId);
    }

    /**
     * @param  list<string>  $statuses
     * @return Builder<Project>
     */
    private function attentionProjectsQuery(int $userId, array $statuses, ?int $projectId): Builder
    {
        $query = Project::query()
            ->where('user_id', $userId)
            ->whereIn('status', $statuses)
            ->select([
                'projects.uuid as item_uuid',
                'projects.name as item_title',
                'projects.status as item_status',
                'projects.created_at as item_created_at',
                'projects.uuid as project_uuid',
                'projects.name as project_name',
            ]);

        if ($projectId !== null) {
            $query->whereKey($projectId);
        }

        return $query;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $statuses
     * @return Builder<Model>
     */
    private function attentionChildQuery(string $model, int $userId, array $statuses, ?int $projectId): Builder
    {
        $table = (new $model)->getTable();

        $query = $model::query()
            ->join('projects', 'projects.id', '=', "{$table}.project_id")
            ->where('projects.user_id', $userId)
            ->whereNull('projects.deleted_at')
            ->whereIn("{$table}.status", $statuses)
            ->select([
                "{$table}.uuid as item_uuid",
                "{$table}.title as item_title",
                "{$table}.status as item_status",
                "{$table}.created_at as item_created_at",
                'projects.uuid as project_uuid',
                'projects.name as project_name',
            ]);

        if ($projectId !== null) {
            $query->where('projects.id', $projectId);
        }

        return $query;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $statuses
     * @return array{total: int, by_status: array<string, int>}
     */
    private function ownedChildStatusCounts(string $model, int $userId, array $statuses, ?string $from, ?string $to): array
    {
        $table = (new $model)->getTable();
        $query = $this->ownedChildQuery($model, $userId, $from, $to);

        $rows = $query
            ->selectRaw("{$table}.status as status, COUNT(*) as aggregate")
            ->groupBy("{$table}.status")
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) $rows->sum(),
            'by_status' => $this->fillStatusCounts($statuses, $rows),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @return Builder<Model>
     */
    private function ownedChildQuery(string $model, int $userId, ?string $from, ?string $to): Builder
    {
        $table = (new $model)->getTable();

        $query = $model::query()
            ->join('projects', 'projects.id', '=', "{$table}.project_id")
            ->where('projects.user_id', $userId)
            ->whereNull('projects.deleted_at');

        $this->constrainCreatedAt($query, "{$table}.created_at", $from, $to);

        return $query;
    }

    /**
     * @return Builder<Project>
     */
    private function ownedProjectQuery(int $userId, ?string $from, ?string $to): Builder
    {
        $query = Project::query()->where('user_id', $userId);
        $this->constrainCreatedAt($query, 'created_at', $from, $to);

        return $query;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Collection<string, int>
     */
    private function dailyCounts(Builder $query, string $column): Collection
    {
        $day = $this->dayExpression($column);

        return $query
            ->selectRaw("{$day} as day, COUNT(*) as aggregate")
            ->groupByRaw($day)
            ->orderByRaw($day)
            ->pluck('aggregate', 'day')
            ->map(fn (mixed $count): int => (int) $count);
    }

    private function dayExpression(string $column): string
    {
        $driver = Project::query()->getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return "date({$column})";
        }

        return "DATE({$column})";
    }

    /**
     * @param  Builder<Model>  $query
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
     * @param  list<string>  $statuses
     * @param  Collection<string, mixed>  $rows
     * @return array<string, int>
     */
    private function fillStatusCounts(array $statuses, Collection $rows): array
    {
        $counts = [];

        foreach ($statuses as $status) {
            $counts[$status] = (int) ($rows[$status] ?? 0);
        }

        return $counts;
    }
}
