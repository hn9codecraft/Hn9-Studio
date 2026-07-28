<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ProjectInput;
use App\Repositories\Contracts\ProjectInputRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<ProjectInput>
 */
class ProjectInputRepository extends BaseRepository implements ProjectInputRepositoryInterface
{
    /**
     * @return Builder<ProjectInput>
     */
    protected function query(): Builder
    {
        return ProjectInput::query();
    }

    protected function filterable(): array
    {
        return ['type', 'deliverable_type', 'platform', 'language'];
    }

    public function forProject(int $projectId): Collection
    {
        return $this->query()->where('project_id', $projectId)->latest('id')->get();
    }
}
