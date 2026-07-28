<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Ownership-based authorization for projects. This is the reference pattern
 * for every user-owned resource: the owner (or an admin) may act on it.
 * Auto-discovered by Laravel via the App\Policies\{Model}Policy convention.
 */
class ProjectPolicy
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

    public function view(User $user, Project $project): bool
    {
        return $this->owns($user, $project);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('project.create');
    }

    public function update(User $user, Project $project): bool
    {
        return $this->owns($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->owns($user, $project);
    }

    public function restore(User $user, Project $project): bool
    {
        return $this->owns($user, $project);
    }

    public function forceDelete(User $user, Project $project): bool
    {
        return $this->owns($user, $project);
    }

    /**
     * Whether the user owns the given project.
     */
    private function owns(User $user, Project $project): bool
    {
        return $user->id === $project->user_id;
    }
}
