<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Story\Models\StoryScene;

class StoryScenePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, StoryScene $scene): bool
    {
        return $this->owns($user, $scene);
    }

    public function update(User $user, StoryScene $scene): bool
    {
        return $this->owns($user, $scene);
    }

    public function archive(User $user, StoryScene $scene): bool
    {
        return $this->owns($user, $scene);
    }

    /**
     * Comment, submit, approve, rework, and regenerate.
     * Admin is already allowed by before(). A non-owner is not.
     */
    public function review(User $user, StoryScene $scene): bool
    {
        return $this->owns($user, $scene);
    }

    private function owns(User $user, StoryScene $scene): bool
    {
        $reel = $scene->reel ?? $scene->reel()->first();
        $workspace = $reel?->workspace ?? $reel?->workspace()->first();
        $project = $workspace?->project ?? $workspace?->project()->first();

        return $project !== null && $user->id === $project->user_id;
    }
}
