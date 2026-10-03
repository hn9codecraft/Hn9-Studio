<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Story\Models\StoryProductionPlan;

/**
 * Production plans are read through the owning project. They are never written from a request.
 */
class StoryProductionPlanPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, StoryProductionPlan $plan): bool
    {
        $workspace = $plan->workspace ?? $plan->workspace()->first();
        $project = $workspace?->project ?? $workspace?->project()->first();

        return $project !== null && $user->id === $project->user_id;
    }
}
