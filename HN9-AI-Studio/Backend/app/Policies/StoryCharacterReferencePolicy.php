<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Story\Models\StoryCharacterReference;

/**
 * Character reference access and review follow character → workspace → project ownership.
 */
class StoryCharacterReferencePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, StoryCharacterReference $reference): bool
    {
        return $this->owns($user, $reference);
    }

    public function create(User $user, StoryCharacterReference $reference): bool
    {
        return $this->owns($user, $reference);
    }

    public function update(User $user, StoryCharacterReference $reference): bool
    {
        return $this->owns($user, $reference);
    }

    public function review(User $user, StoryCharacterReference $reference): bool
    {
        return $this->owns($user, $reference);
    }

    public function download(User $user, StoryCharacterReference $reference): bool
    {
        return $this->owns($user, $reference);
    }

    private function owns(User $user, StoryCharacterReference $reference): bool
    {
        $character = $reference->character ?? $reference->character()->first();
        $workspace = $character?->workspace ?? $character?->workspace()->first();
        $project = $workspace?->project ?? $workspace?->project()->first();

        return $project !== null && $user->id === $project->user_id;
    }
}
