<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Story\Models\StoryCharacter;

/**
 * Character access follows workspace → project ownership.
 */
class StoryCharacterPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, StoryCharacter $character): bool
    {
        return $this->owns($user, $character);
    }

    public function create(User $user, StoryCharacter $character): bool
    {
        return $this->owns($user, $character);
    }

    public function update(User $user, StoryCharacter $character): bool
    {
        return $this->owns($user, $character);
    }

    public function delete(User $user, StoryCharacter $character): bool
    {
        return $this->owns($user, $character);
    }

    public function review(User $user, StoryCharacter $character): bool
    {
        return $this->owns($user, $character);
    }

    private function owns(User $user, StoryCharacter $character): bool
    {
        $workspace = $character->workspace ?? $character->workspace()->first();
        $project = $workspace?->project ?? $workspace?->project()->first();

        return $project !== null && $user->id === $project->user_id;
    }
}
