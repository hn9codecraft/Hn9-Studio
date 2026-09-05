<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\DTOs\Image\CreateImageData;
use App\DTOs\Image\UpdateImageData;
use App\Models\Image;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Business operations for project image requests.
 */
interface ImageServiceInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Image>
     */
    public function paginateForProject(Project $project, int $perPage = 15, array $filters = []): LengthAwarePaginator;

    public function getByUuid(string $uuid): Image;

    public function create(CreateImageData $data, ?User $causer = null): Image;

    public function update(Image $image, UpdateImageData $data, ?User $causer = null): Image;

    public function delete(Image $image, ?User $causer = null): bool;
}
