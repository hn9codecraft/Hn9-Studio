<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Story\Models\StoryBible;

/**
 * Story Bible access follows workspace → project ownership.
 */
class StoryBiblePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, StoryBible $bible): bool
    {
        return $this->owns($user, $bible);
    }

    public function update(User $user, StoryBible $bible): bool
    {
        return $this->owns($user, $bible);
    }

    private function owns(User $user, StoryBible $bible): bool
    {
        $workspace = $bible->workspace ?? $bible->workspace()->first();
        $project = $workspace?->project ?? $workspace?->project()->first();

        return $project !== null && $user->id === $project->user_id;
    }
}
