<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Contracts\Services\ProjectServiceInterface;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryVideoEngineInterface;
use App\Story\Contracts\StoryWorkspaceRepositoryInterface;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Story\Models\StoryWorkspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class StoryWorkspaceService implements StoryWorkspaceServiceInterface
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private StoryWorkspaceRepositoryInterface $workspaces,
        private StoryVideoEngineInterface $engine,
    ) {}

    public function selectableProjects(User $user, int $perPage, array $filters = []): LengthAwarePaginator
    {
        return $this->projects->paginateForUser($user, $perPage, $filters);
    }

    public function workspaceForProject(Project $project): StoryWorkspace
    {
        return $this->workspaces->firstOrCreateForProject($project)->loadMissing('project');
    }

    public function capabilityCatalog(): array
    {
        return $this->engine->catalog();
    }
}
