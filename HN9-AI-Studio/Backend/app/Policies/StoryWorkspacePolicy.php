<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryWorkspace;

/**
 * Story access is project ownership. No second auth system.
 */
class StoryWorkspacePolicy
{
    public const ACCESS_PERMISSION = 'story.access';

    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StoryWorkspace $workspace): bool
    {
        $project = $workspace->project ?? $workspace->project()->first();

        return $project !== null && $this->ownsProject($user, $project);
    }

    public function select(User $user, Project $project): bool
    {
        return $this->ownsProject($user, $project);
    }

    private function ownsProject(User $user, Project $project): bool
    {
        return $user->id === $project->user_id;
    }
}
