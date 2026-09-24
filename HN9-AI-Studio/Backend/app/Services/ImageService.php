<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\ImageReviewServiceInterface;
use App\Contracts\Services\ImageServiceInterface;
use App\DTOs\Image\CreateImageData;
use App\DTOs\Image\UpdateImageData;
use App\Enums\ImageStatus;
use App\Exceptions\ImageWorkflowException;
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
    /**
     * @var list<string>
     */
    private const RELATIONS = [
        'project',
        'script',
        'parentImage',
        'generatedContent',
        'generatedAsset',
        'file',
        'latestReviewEvent.user',
        'latestReworkEvent.user',
    ];

    public function __construct(
        private ImageRepositoryInterface $images,
        private ActivityLoggerInterface $activity,
        private ImageReviewServiceInterface $reviews,
    ) {}

    public function paginateForProject(Project $project, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return $this->images->paginateForProject($project->getKey(), $perPage, $filters, self::RELATIONS);
    }

    public function getByUuid(string $uuid): Image
    {
        return $this->images->findByUuidOrFail($uuid, self::RELATIONS);
    }

    public function create(CreateImageData $data, ?User $causer = null): Image
    {
        $attributes = $data->toArray();

        if (isset($attributes['status']) && ! in_array($attributes['status'], ImageStatus::assignableValues(), true)) {
            throw ImageWorkflowException::statusNotAssignable('new', (string) $attributes['status']);
        }

        $image = $this->images->create($attributes);
        $image->load(self::RELATIONS);

        $this->activity->log('image.created', $image, $causer, 'Image request created');

        return $image;
    }

    public function update(Image $image, UpdateImageData $data, ?User $causer = null): Image
    {
        $payload = $data->toArray();
        $current = $image->statusEnum();

        if (array_key_exists('status', $payload)) {
            $target = (string) $payload['status'];

            if (! in_array($target, ImageStatus::assignableValues(), true)) {
                throw ImageWorkflowException::statusNotAssignable($image->uuid, $target);
            }

            if (! in_array($current, [ImageStatus::Draft, ImageStatus::Pending, ImageStatus::Archived], true)) {
                throw ImageWorkflowException::statusNotAssignable($image->uuid, $target);
            }
        }

        $touchesContent = array_key_exists('title', $payload)
            || array_key_exists('prompt', $payload)
            || array_key_exists('negative_prompt', $payload)
            || array_key_exists('aspect_ratio', $payload);

        if ($touchesContent && ! $current->allowsContentEdit()) {
            throw ImageWorkflowException::editLocked($image->uuid, $current->value);
        }

        $wasNeedsRework = $current === ImageStatus::NeedsRework
            && $touchesContent
            && $this->contentChanged($image, $payload);

        $image = $this->images->update($image, $payload);
        $image->load(self::RELATIONS);

        $this->activity->log('image.updated', $image, $causer, 'Image request updated');

        if ($wasNeedsRework && $causer !== null) {
            $this->reviews->recordReworked($image, $causer);
            $image->load(self::RELATIONS);
        }

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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function contentChanged(Image $image, array $payload): bool
    {
        foreach (['title', 'prompt', 'negative_prompt', 'aspect_ratio'] as $field) {
            if (array_key_exists($field, $payload) && (string) $payload[$field] !== (string) $image->{$field}) {
                return true;
            }
        }

        return false;
    }
}
