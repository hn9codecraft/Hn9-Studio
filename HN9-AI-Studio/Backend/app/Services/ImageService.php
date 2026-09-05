<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\ImageServiceInterface;
use App\DTOs\Image\CreateImageData;
use App\DTOs\Image\UpdateImageData;
use App\Models\Image;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Contracts\ImageRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Business rules for studio image requests. Persistence is delegated to the
 * repository. This service never invents provider output.
 */
final readonly class ImageService implements ImageServiceInterface
{
    public function __construct(
        private ImageRepositoryInterface $images,
        private ActivityLoggerInterface $activity,
    ) {}

    public function paginateForProject(Project $project, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return $this->images->paginateForProject($project->getKey(), $perPage, $filters, ['project']);
    }

    public function getByUuid(string $uuid): Image
    {
        return $this->images->findByUuidOrFail($uuid, ['project']);
    }

    public function create(CreateImageData $data, ?User $causer = null): Image
    {
        $image = $this->images->create($data->toArray());
        $image->load('project');

        $this->activity->log('image.created', $image, $causer, 'Image request created');

        return $image;
    }

    public function update(Image $image, UpdateImageData $data, ?User $causer = null): Image
    {
        $image = $this->images->update($image, $data->toArray());
        $image->load('project');

        $this->activity->log('image.updated', $image, $causer, 'Image request updated');

        return $image;
    }

    public function delete(Image $image, ?User $causer = null): bool
    {
        $deleted = $this->images->delete($image);

        if ($deleted) {
            $this->activity->log('image.deleted', $image, $causer, 'Image request deleted');
        }

        return $deleted;
    }
}
