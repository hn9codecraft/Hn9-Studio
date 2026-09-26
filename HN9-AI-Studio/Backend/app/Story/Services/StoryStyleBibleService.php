<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Story\Contracts\StoryStyleBibleRepositoryInterface;
use App\Story\Contracts\StoryStyleBibleServiceInterface;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Story\Models\StoryStyleBible;

final readonly class StoryStyleBibleService implements StoryStyleBibleServiceInterface
{
    public function __construct(
        private StoryWorkspaceServiceInterface $workspaces,
        private StoryStyleBibleRepositoryInterface $styles,
    ) {}

    public function styleForProject(Project $project): StoryStyleBible
    {
        $workspace = $this->workspaces->workspaceForProject($project);

        return $this->styles->firstOrCreateForWorkspace($workspace)
            ->loadMissing(['approvedReference', 'workspace.project']);
    }

    public function updateForProject(Project $project, array $attributes): StoryStyleBible
    {
        $style = $this->styleForProject($project);

        return $this->styles->update($style, $attributes);
    }
}
