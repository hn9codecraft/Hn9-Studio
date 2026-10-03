<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Story\Models\StoryPlan;

class StoryPlanPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, StoryPlan $plan): bool
    {
        return $this->owns($user, $plan);
    }

    public function create(User $user, StoryPlan $plan): bool
    {
        return $this->owns($user, $plan);
    }

    public function update(User $user, StoryPlan $plan): bool
    {
        return $this->owns($user, $plan);
    }

    public function generate(User $user, StoryPlan $plan): bool
    {
        return $this->owns($user, $plan);
    }

    public function approve(User $user, StoryPlan $plan): bool
    {
        return $this->owns($user, $plan);
    }

    private function owns(User $user, StoryPlan $plan): bool
    {
        $workspace = $plan->workspace ?? $plan->workspace()->first();
        $project = $workspace?->project ?? $workspace?->project()->first();

        return $project !== null && $user->id === $project->user_id;
    }
}
