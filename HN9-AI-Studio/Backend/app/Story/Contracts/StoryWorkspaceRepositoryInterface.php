<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Models\Project;
use App\Story\Models\StoryWorkspace;

interface StoryWorkspaceRepositoryInterface
{
    public function findByProject(Project $project): ?StoryWorkspace;

    public function firstOrCreateForProject(Project $project): StoryWorkspace;
}
