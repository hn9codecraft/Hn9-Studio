<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\DTOs\ProjectAsset\CreateProjectAssetData;
use App\DTOs\ProjectAsset\UpdateProjectAssetData;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Business operations for studio project assets.
 */
interface ProjectAssetServiceInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ProjectAsset>
     */
    public function paginateForProject(Project $project, int $perPage = 15, array $filters = []): LengthAwarePaginator;

    public function getByUuid(string $uuid): ProjectAsset;

    public function create(CreateProjectAssetData $data, ?User $causer = null): ProjectAsset;

    public function update(ProjectAsset $asset, UpdateProjectAssetData $data, ?User $causer = null): ProjectAsset;

    public function delete(ProjectAsset $asset, ?User $causer = null): bool;
}
