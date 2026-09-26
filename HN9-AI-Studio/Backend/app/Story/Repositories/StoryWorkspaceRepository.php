<?php

declare(strict_types=1);

namespace App\Story\Repositories;

use App\Models\Project;
use App\Story\Contracts\StoryWorkspaceRepositoryInterface;
use App\Story\Enums\StoryWorkspaceStatus;
use App\Story\Models\StoryWorkspace;

final class StoryWorkspaceRepository implements StoryWorkspaceRepositoryInterface
{
    public function findByProject(Project $project): ?StoryWorkspace
    {
        return StoryWorkspace::query()
            ->where('project_id', $project->getKey())
            ->first();
    }

    public function firstOrCreateForProject(Project $project): StoryWorkspace
    {
        return StoryWorkspace::query()->firstOrCreate(
            ['project_id' => $project->getKey()],
            ['status' => StoryWorkspaceStatus::Ready->value],
        );
    }
}
