<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for the audit trail.
 *
 * The activity log is cross-tenant by nature: one row can reference any user's
 * subject, IP address and before/after payload. There is no per-record owner to
 * resolve, so listing it is restricted to administrators outright rather than
 * scoped. Follows the {@see UserPolicy} reference pattern and is auto-discovered
 * via the App\Policies\{Model}Policy convention.
 */
class ActivityLogPolicy
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
        return $user->isAdmin();
    }
}
