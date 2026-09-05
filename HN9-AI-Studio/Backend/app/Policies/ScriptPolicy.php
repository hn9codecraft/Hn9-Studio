<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\Script;
use App\Models\User;

/**
 * Ownership for studio scripts is resolved through the owning project.
 */
class ScriptPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user, ?Project $project = null): bool
    {
        if ($project === null) {
            return true;
        }

        return $this->ownsProject($user, $project);
    }

    public function view(User $user, Script $script): bool
    {
        return $this->owns($user, $script);
    }

    public function create(User $user, ?Project $project = null): bool
    {
        if ($project === null) {
            return true;
        }

        return $this->ownsProject($user, $project);
    }

    public function update(User $user, Script $script): bool
    {
        return $this->owns($user, $script);
    }

    public function delete(User $user, Script $script): bool
    {
        return $this->owns($user, $script);
    }

    private function owns(User $user, Script $script): bool
    {
        $project = $script->project ?? $script->project()->first();

        return $project !== null && $this->ownsProject($user, $project);
    }

    private function ownsProject(User $user, Project $project): bool
    {
        return $user->id === $project->user_id;
    }
}
