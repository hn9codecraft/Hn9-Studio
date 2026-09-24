<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Export;
use App\Models\Project;
use App\Models\User;

/**
 * Export access follows project ownership. A user may only see or download
 * packages they created or that belong to a project they own.
 */
class ExportPolicy
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

    public function view(User $user, Export $export): bool
    {
        return $this->owns($user, $export);
    }

    public function create(User $user, ?Project $project = null): bool
    {
        if ($project === null) {
            return true;
        }

        return $this->ownsProject($user, $project);
    }

    public function download(User $user, Export $export): bool
    {
        return $this->owns($user, $export);
    }

    private function owns(User $user, Export $export): bool
    {
        if ($export->user_id === $user->getKey()) {
            return true;
        }

        $project = $export->project ?? $export->project()->first();

        return $project !== null && $this->ownsProject($user, $project);
    }

    private function ownsProject(User $user, Project $project): bool
    {
        return $user->id === $project->user_id;
    }
}
