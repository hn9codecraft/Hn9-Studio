<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\VideoServiceInterface;
use App\DTOs\Video\CreateVideoData;
use App\DTOs\Video\UpdateVideoData;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use App\Repositories\Contracts\VideoRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Business rules for studio video requests. Persistence is delegated to the
 * repository. This service never invents provider output.
 */
final readonly class VideoService implements VideoServiceInterface
{
    public function __construct(
        private VideoRepositoryInterface $videos,
        private ActivityLoggerInterface $activity,
    ) {}

    public function paginateForProject(Project $project, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return $this->videos->paginateForProject($project->getKey(), $perPage, $filters, ['project']);
    }

    public function getByUuid(string $uuid): Video
    {
        return $this->videos->findByUuidOrFail($uuid, ['project']);
    }

    public function create(CreateVideoData $data, ?User $causer = null): Video
    {
        $video = $this->videos->create($data->toArray());
        $video->load('project');

        $this->activity->log('video.created', $video, $causer, 'Video request created');

        return $video;
    }

    public function update(Video $video, UpdateVideoData $data, ?User $causer = null): Video
    {
        $video = $this->videos->update($video, $data->toArray());
        $video->load('project');

        $this->activity->log('video.updated', $video, $causer, 'Video request updated');

        return $video;
    }

    public function delete(Video $video, ?User $causer = null): bool
    {
        $deleted = $this->videos->delete($video);

        if ($deleted) {
            $this->activity->log('video.deleted', $video, $causer, 'Video request deleted');
        }

        return $deleted;
    }
}
