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
