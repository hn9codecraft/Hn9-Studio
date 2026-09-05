<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\DTOs\Video\CreateVideoData;
use App\DTOs\Video\UpdateVideoData;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Business operations for project video requests.
 */
interface VideoServiceInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Video>
     */
    public function paginateForProject(Project $project, int $perPage = 15, array $filters = []): LengthAwarePaginator;

    public function getByUuid(string $uuid): Video;

    public function create(CreateVideoData $data, ?User $causer = null): Video;

    public function update(Video $video, UpdateVideoData $data, ?User $causer = null): Video;

    public function delete(Video $video, ?User $causer = null): bool;
}
