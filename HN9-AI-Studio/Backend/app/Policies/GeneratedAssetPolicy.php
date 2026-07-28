<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GeneratedAsset;
use App\Models\User;

/**
 * Ownership-based authorization for generated assets, resolved through the
 * owning project. Follows the {@see ProjectPolicy} reference pattern.
 * Auto-discovered via the App\Policies\{Model}Policy convention.
 */
class GeneratedAssetPolicy
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

    public function view(User $user, GeneratedAsset $asset): bool
    {
        return $this->owns($user, $asset);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('asset.create');
    }

    public function update(User $user, GeneratedAsset $asset): bool
    {
        return $this->owns($user, $asset);
    }

    public function delete(User $user, GeneratedAsset $asset): bool
    {
        return $this->owns($user, $asset);
    }

    /**
     * Whether the user owns the asset's project.
     */
    private function owns(User $user, GeneratedAsset $asset): bool
    {
        return $user->id === $asset->project?->user_id;
    }
}
