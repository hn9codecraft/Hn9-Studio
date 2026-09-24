<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Models\Video;

/**
 * Ownership for studio video requests is resolved through the owning project.
 */
class VideoPolicy
{
    public const REVIEW_PERMISSION = 'video.review';

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

    public function view(User $user, Video $video): bool
    {
        return $this->owns($user, $video);
    }

    public function create(User $user, ?Project $project = null): bool
    {
        if ($project === null) {
            return true;
        }

        return $this->ownsProject($user, $project);
    }

    public function update(User $user, Video $video): bool
    {
        return $this->owns($user, $video);
    }

    public function delete(User $user, Video $video): bool
    {
        return $this->owns($user, $video);
    }

    public function submit(User $user, Video $video): bool
    {
        return $this->owns($user, $video);
    }

    public function review(User $user, Video $video): bool
    {
        return $this->owns($user, $video) && $user->hasPermission(self::REVIEW_PERMISSION);
    }

    public function viewReviewHistory(User $user, Video $video): bool
    {
        return $this->owns($user, $video);
    }

    private function owns(User $user, Video $video): bool
    {
        $project = $video->project ?? $video->project()->first();

        return $project !== null && $this->ownsProject($user, $project);
    }

    private function ownsProject(User $user, Project $project): bool
    {
        return $user->id === $project->user_id;
    }
}
