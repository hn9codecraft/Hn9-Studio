<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AiProvider;
use App\Models\User;

/**
 * Administrative authorization for AI provider configuration. Provider setup
 * is a privileged operation — only administrators may manage it. This is the
 * reference pattern for admin-only resources.
 */
class AiProviderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, AiProvider $provider): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, AiProvider $provider): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, AiProvider $provider): bool
    {
        return $user->isAdmin();
    }
}
