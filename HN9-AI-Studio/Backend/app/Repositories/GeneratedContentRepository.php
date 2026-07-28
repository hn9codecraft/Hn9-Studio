<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\GeneratedContent;
use App\Repositories\Contracts\GeneratedContentRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<GeneratedContent>
 */
class GeneratedContentRepository extends BaseRepository implements GeneratedContentRepositoryInterface
{
    /**
     * @return Builder<GeneratedContent>
     */
    protected function query(): Builder
    {
        return GeneratedContent::query();
    }

    protected function filterable(): array
    {
        return ['type', 'status', 'channel', 'language'];
    }

    public function forProject(int $projectId, array $with = []): Collection
    {
        return $this->query()->with($with)->where('project_id', $projectId)->latest('id')->get();
    }

    public function forProjectOfType(int $projectId, string $type): Collection
    {
        return $this->query()
            ->where('project_id', $projectId)
            ->where('type', $type)
            ->latest('id')
            ->get();
    }

    public function latestVersion(int $projectId, string $type): int
    {
        return (int) $this->query()
            ->where('project_id', $projectId)
            ->where('type', $type)
            ->max('version');
    }
}
