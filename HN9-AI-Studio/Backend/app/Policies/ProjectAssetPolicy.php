<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\User;

/**
 * Ownership for studio project assets is resolved through the owning project.
 */
class ProjectAssetPolicy
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

    public function view(User $user, ProjectAsset $asset): bool
    {
        return $this->owns($user, $asset);
    }

    public function create(User $user, ?Project $project = null): bool
    {
        if ($project === null) {
            return true;
        }

        return $this->ownsProject($user, $project);
    }

    public function update(User $user, ProjectAsset $asset): bool
    {
        return $this->owns($user, $asset);
    }

    public function delete(User $user, ProjectAsset $asset): bool
    {
        return $this->owns($user, $asset);
    }

    private function owns(User $user, ProjectAsset $asset): bool
    {
        $project = $asset->project ?? $asset->project()->first();

        return $project !== null && $this->ownsProject($user, $project);
    }

    private function ownsProject(User $user, Project $project): bool
    {
        return $user->id === $project->user_id;
    }
}
