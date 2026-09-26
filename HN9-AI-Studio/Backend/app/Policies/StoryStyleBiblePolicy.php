<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Story\Models\StoryStyleBible;

class StoryStyleBiblePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, StoryStyleBible $style): bool
    {
        return $this->owns($user, $style);
    }

    public function update(User $user, StoryStyleBible $style): bool
    {
        return $this->owns($user, $style);
    }

    private function owns(User $user, StoryStyleBible $style): bool
    {
        $workspace = $style->workspace ?? $style->workspace()->first();
        $project = $workspace?->project ?? $workspace?->project()->first();

        return $project !== null && $user->id === $project->user_id;
    }
}
