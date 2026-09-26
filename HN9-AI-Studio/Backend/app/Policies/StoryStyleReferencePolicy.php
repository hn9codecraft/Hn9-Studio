<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Story\Models\StoryStyleReference;

class StoryStyleReferencePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, StoryStyleReference $reference): bool
    {
        return $this->owns($user, $reference);
    }

    public function create(User $user, StoryStyleReference $reference): bool
    {
        return $this->owns($user, $reference);
    }

    public function update(User $user, StoryStyleReference $reference): bool
    {
        return $this->owns($user, $reference);
    }

    public function review(User $user, StoryStyleReference $reference): bool
    {
        return $this->owns($user, $reference);
    }

    public function download(User $user, StoryStyleReference $reference): bool
    {
        return $this->owns($user, $reference);
    }

    private function owns(User $user, StoryStyleReference $reference): bool
    {
        $style = $reference->styleBible ?? $reference->styleBible()->first();
        $workspace = $style?->workspace ?? $style?->workspace()->first();
        $project = $workspace?->project ?? $workspace?->project()->first();

        return $project !== null && $user->id === $project->user_id;
    }
}
