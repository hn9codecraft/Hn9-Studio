<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\AssetServiceInterface;
use App\DTOs\Asset\AssetData;
use App\Models\GeneratedAsset;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Contracts\AssetRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Business operations for generated media assets. Manages asset *records* only
 * — media generation itself belongs to a later sprint.
 */
final readonly class AssetService implements AssetServiceInterface
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private ActivityLoggerInterface $activity,
    ) {}

    public function forProject(Project $project): Collection
    {
        return $this->assets->forProject($project->getKey());
    }

    public function getByUuid(string $uuid): GeneratedAsset
    {
        return $this->assets->findByUuidOrFail($uuid);
    }

    public function create(AssetData $data, ?User $causer = null): GeneratedAsset
    {
        $asset = $this->assets->create($data->toArray());

        $this->activity->log('asset.created', $asset, $causer, 'Generated asset recorded');

        return $asset;
    }

    public function delete(GeneratedAsset $asset, ?User $causer = null): bool
    {
        $deleted = $this->assets->delete($asset);

        if ($deleted) {
            $this->activity->log('asset.deleted', $asset, $causer, 'Generated asset deleted');
        }

        return $deleted;
    }
}
