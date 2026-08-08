<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\DTOs\Asset\AssetData;
use App\Models\GeneratedAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Business operations for generated media assets (record management only — no
 * media is produced in this sprint).
 */
interface AssetServiceInterface
{
    /**
     * All assets for a project.
     *
     * @return Collection<int, GeneratedAsset>
     */
    public function forProject(Project $project): Collection;

    public function paginateForUser(User $user, int $perPage = 15, array $filters = []): LengthAwarePaginator;

    public function getByUuid(string $uuid): GeneratedAsset;

    public function create(AssetData $data, ?User $causer = null): GeneratedAsset;

    public function update(GeneratedAsset $asset, array $data, ?User $causer = null): GeneratedAsset;

    public function setFavorite(GeneratedAsset $asset, bool $favorite, ?User $causer = null): GeneratedAsset;

    public function delete(GeneratedAsset $asset, ?User $causer = null): bool;
}
