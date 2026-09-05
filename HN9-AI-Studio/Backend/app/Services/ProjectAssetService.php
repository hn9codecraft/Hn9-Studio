<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\ProjectAssetServiceInterface;
use App\DTOs\ProjectAsset\CreateProjectAssetData;
use App\DTOs\ProjectAsset\UpdateProjectAssetData;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\User;
use App\Repositories\Contracts\ProjectAssetRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Business rules for studio project assets. This service never invents files
 * or generated URLs.
 */
final readonly class ProjectAssetService implements ProjectAssetServiceInterface
{
    public function __construct(
        private ProjectAssetRepositoryInterface $assets,
        private ActivityLoggerInterface $activity,
    ) {}

    public function paginateForProject(Project $project, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return $this->assets->paginateForProject($project->getKey(), $perPage, $filters, ['project']);
    }

    public function getByUuid(string $uuid): ProjectAsset
    {
        return $this->assets->findByUuidOrFail($uuid, ['project']);
    }

    public function create(CreateProjectAssetData $data, ?User $causer = null): ProjectAsset
    {
        $asset = $this->assets->create($data->toArray());
        $asset->load('project');

        $this->activity->log('project_asset.created', $asset, $causer, 'Project asset created');

        return $asset;
    }

    public function update(ProjectAsset $asset, UpdateProjectAssetData $data, ?User $causer = null): ProjectAsset
    {
        $asset = $this->assets->update($asset, $data->toArray());
        $asset->load('project');

        $this->activity->log('project_asset.updated', $asset, $causer, 'Project asset updated');

        return $asset;
    }

    public function delete(ProjectAsset $asset, ?User $causer = null): bool
    {
        $deleted = $this->assets->delete($asset);

        if ($deleted) {
            $this->activity->log('project_asset.deleted', $asset, $causer, 'Project asset deleted');
        }

        return $deleted;
    }
}
