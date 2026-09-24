<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\VideoReviewServiceInterface;
use App\Contracts\Services\VideoServiceInterface;
use App\DTOs\Video\CreateVideoData;
use App\DTOs\Video\UpdateVideoData;
use App\Enums\VideoStatus;
use App\Exceptions\VideoWorkflowException;
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
    /**
     * @var list<string>
     */
    private const RELATIONS = [
        'project',
        'script',
        'image',
        'parentVideo',
        'generatedAsset',
        'file',
        'latestReviewEvent.user',
        'latestReworkEvent.user',
    ];

    public function __construct(
        private VideoRepositoryInterface $videos,
        private ActivityLoggerInterface $activity,
        private VideoReviewServiceInterface $reviews,
    ) {}

    public function paginateForProject(Project $project, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return $this->videos->paginateForProject($project->getKey(), $perPage, $filters, self::RELATIONS);
    }

    public function getByUuid(string $uuid): Video
    {
        return $this->videos->findByUuidOrFail($uuid, self::RELATIONS);
    }

    public function create(CreateVideoData $data, ?User $causer = null): Video
    {
        $attributes = $data->toArray();

        if (isset($attributes['status']) && ! in_array($attributes['status'], VideoStatus::assignableValues(), true)) {
            throw VideoWorkflowException::invalidTransition('new', 'created', (string) $attributes['status']);
        }

        $video = $this->videos->create($attributes);
        $video->load(self::RELATIONS);

        $this->activity->log('video.created', $video, $causer, 'Video request created');

        return $video;
    }

    public function update(Video $video, UpdateVideoData $data, ?User $causer = null): Video
    {
        $payload = $data->toArray();
        $current = $video->statusEnum();

        if (array_key_exists('status', $payload)) {
            $target = (string) $payload['status'];

            if (! in_array($target, VideoStatus::assignableValues(), true)) {
                throw VideoWorkflowException::invalidTransition($video->uuid, 'updated', $target);
            }

            if (! in_array($current, [VideoStatus::Draft, VideoStatus::Pending, VideoStatus::Archived], true)) {
                throw VideoWorkflowException::invalidTransition($video->uuid, 'updated', $target);
            }
        }

        $touchesContent = array_key_exists('title', $payload)
            || array_key_exists('prompt', $payload)
            || array_key_exists('negative_prompt', $payload)
            || array_key_exists('aspect_ratio', $payload)
            || array_key_exists('duration', $payload);

        if ($touchesContent && ! $current->allowsContentEdit()) {
            throw VideoWorkflowException::editLocked($video->uuid, $current->value);
        }

        $wasNeedsRework = $current === VideoStatus::NeedsRework
            && $touchesContent
            && $this->contentChanged($video, $payload);

        $video = $this->videos->update($video, $payload);
        $video->load(self::RELATIONS);

        $this->activity->log('video.updated', $video, $causer, 'Video request updated');

        if ($wasNeedsRework && $causer !== null) {
            $this->reviews->recordReworked($video, $causer);
            $video->load(self::RELATIONS);
        }

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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function contentChanged(Video $video, array $payload): bool
    {
        foreach (['title', 'prompt', 'negative_prompt', 'aspect_ratio'] as $field) {
            if (array_key_exists($field, $payload) && (string) $payload[$field] !== (string) $video->{$field}) {
                return true;
            }
        }

        if (array_key_exists('duration', $payload) && (int) $payload['duration'] !== (int) $video->duration) {
            return true;
        }

        return false;
    }
}
