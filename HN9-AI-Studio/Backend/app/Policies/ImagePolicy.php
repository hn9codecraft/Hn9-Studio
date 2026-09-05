<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Image;
use App\Models\Project;
use App\Models\User;

/**
 * Ownership for studio image requests is resolved through the owning project.
 */
class ImagePolicy
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

    public function view(User $user, Image $image): bool
    {
        return $this->owns($user, $image);
    }

    public function create(User $user, ?Project $project = null): bool
    {
        if ($project === null) {
            return true;
        }

        return $this->ownsProject($user, $project);
    }

    public function update(User $user, Image $image): bool
    {
        return $this->owns($user, $image);
    }

    public function delete(User $user, Image $image): bool
    {
        return $this->owns($user, $image);
    }

    private function owns(User $user, Image $image): bool
    {
        $project = $image->project ?? $image->project()->first();

        return $project !== null && $this->ownsProject($user, $project);
    }

    private function ownsProject(User $user, Project $project): bool
    {
        return $user->id === $project->user_id;
    }
}
