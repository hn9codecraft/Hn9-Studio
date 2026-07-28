<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowRun;

/**
 * Ownership-based authorization for workflow runs, resolved through the owning
 * project. Follows the {@see ProjectPolicy} reference pattern.
 * Auto-discovered via the App\Policies\{Model}Policy convention.
 */
class WorkflowRunPolicy
{
    /**
     * Admins bypass every check.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WorkflowRun $run): bool
    {
        return $this->owns($user, $run);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('workflow.create');
    }

    public function update(User $user, WorkflowRun $run): bool
    {
        return $this->owns($user, $run);
    }

    public function delete(User $user, WorkflowRun $run): bool
    {
        return $this->owns($user, $run);
    }

    /**
     * Whether the user owns the run's project (or launched the run).
     */
    private function owns(User $user, WorkflowRun $run): bool
    {
        return $user->id === $run->user_id || $user->id === $run->project?->user_id;
    }
}
