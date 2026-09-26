<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Story\Models\StoryReel;

class StoryReelPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, StoryReel $reel): bool
    {
        return $this->owns($user, $reel);
    }

    public function update(User $user, StoryReel $reel): bool
    {
        return $this->owns($user, $reel);
    }

    public function archive(User $user, StoryReel $reel): bool
    {
        return $this->owns($user, $reel);
    }

    private function owns(User $user, StoryReel $reel): bool
    {
        $workspace = $reel->workspace ?? $reel->workspace()->first();
        $project = $workspace?->project ?? $workspace?->project()->first();

        return $project !== null && $user->id === $project->user_id;
    }
}
