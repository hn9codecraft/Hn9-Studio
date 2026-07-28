<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GeneratedContent;
use App\Models\User;

/**
 * Ownership-based authorization for generated content, resolved through the
 * owning project. Follows the {@see ProjectPolicy} reference pattern.
 * Auto-discovered via the App\Policies\{Model}Policy convention.
 */
class GeneratedContentPolicy
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

    public function view(User $user, GeneratedContent $content): bool
    {
        return $this->owns($user, $content);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('content.create');
    }

    public function update(User $user, GeneratedContent $content): bool
    {
        return $this->owns($user, $content);
    }

    public function delete(User $user, GeneratedContent $content): bool
    {
        return $this->owns($user, $content);
    }

    /**
     * Whether the user owns the content's project.
     */
    private function owns(User $user, GeneratedContent $content): bool
    {
        return $user->id === $content->project?->user_id;
    }
}
