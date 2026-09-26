<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\AI\Contracts\ProviderDispatcherInterface;
use App\AI\Exceptions\ProviderException;
use App\AI\Execution\DispatchOptions;
use App\AI\Requests\ImageRequest;
use App\AI\Responses\ImageResponse;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryStyleBibleServiceInterface;
use App\Story\Contracts\StoryStyleReferenceRepositoryInterface;
use App\Story\Contracts\StoryStyleReferenceServiceInterface;
use App\Story\Enums\StoryStyleReferenceRole;
use App\Story\Enums\StoryStyleReferenceSource;
use App\Story\Enums\StoryStyleReferenceStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Exceptions\StoryStyleException;
use App\Story\Models\StoryStyleReference;
use App\Story\Support\StyleReferencePromptBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Style reference lifecycle. Image generation goes through ProviderDispatcherInterface only.
 */
final readonly class StoryStyleReferenceService implements StoryStyleReferenceServiceInterface
{
    public function __construct(
        private StoryStyleBibleServiceInterface $styles,
        private StoryStyleReferenceRepositoryInterface $references,
        private StyleReferenceBinaryStore $store,
        private StyleReferencePromptBuilder $promptBuilder,
        private ProviderDispatcherInterface $dispatcher,
    ) {}

    public function listForProject(Project $project): Collection
    {
        $style = $this->styles->styleForProject($project);

        return $this->references->listForStyleBible($style);
    }

    public function getForProject(Project $project, string $referenceUuid): StoryStyleReference
    {
        $style = $this->styles->styleForProject($project);
        $reference = $this->references->findByUuidForStyleBible($style, $referenceUuid);

        if ($reference === null) {
            throw StoryException::notFound('Style reference');
        }

        return $reference;
    }

    public function upload(Project $project, UploadedFile $file, ?string $role = null): StoryStyleReference
    {
        $style = $this->styles->styleForProject($project);
        $stored = $this->store->storeUpload($project, $style, $file);

        try {
            return $this->references->create($style, [
                'source' => StoryStyleReferenceSource::Uploaded->value,
                'role' => $this->resolveRole($role)->value,
                'version' => $this->references->nextVersion($style),
                'status' => StoryStyleReferenceStatus::Draft->value,
                'disk' => $stored->disk,
                'path' => $stored->path,
                'original_filename' => $stored->originalFilename,
                'mime_type' => $stored->mimeType,
                'extension' => $stored->extension,
                'size' => $stored->size,
                'width' => $stored->width,
                'height' => $stored->height,
                'checksum' => $stored->checksum,
            ]);
        } catch (Throwable $exception) {
            $this->store->delete($stored->disk, $stored->path);
            throw $exception;
        }
    }

    public function generate(Project $project, array $options = []): StoryStyleReference
    {
        $style = $this->styles->styleForProject($project);
        $prompt = $this->promptBuilder->build($style);

        $provider = is_string($options['provider'] ?? null) ? $options['provider'] : null;
        $model = is_string($options['model'] ?? null) ? $options['model'] : null;

        $request = new ImageRequest(
            prompt: $prompt,
            model: $model,
            size: is_string($options['size'] ?? null) ? $options['size'] : null,
            quality: is_string($options['quality'] ?? null) ? $options['quality'] : null,
            count: 1,
        );

        $dispatchOptions = $provider !== null && $provider !== ''
            ? DispatchOptions::only($provider)
            : null;

        try {
            $result = $this->dispatcher->dispatch($request, $dispatchOptions);
        } catch (ProviderException $exception) {
            throw StoryStyleException::generationFailed($exception->getMessage());
        } catch (Throwable $exception) {
            throw StoryStyleException::generationFailed(
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Image generation failed.',
            );
        }

        $response = $result->response;

        if (! $response instanceof ImageResponse) {
            throw StoryStyleException::generationFailed('Image generation returned an unexpected response.');
        }

        try {
            $stored = $this->store->storeGenerated($project, $style, $response);
        } catch (StoryStyleException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw StoryStyleException::generationFailed('Generated image could not be stored.');
        }

        try {
            return $this->references->create($style, [
                'source' => StoryStyleReferenceSource::Generated->value,
                'role' => $this->resolveRole(is_string($options['role'] ?? null) ? $options['role'] : null)->value,
                'version' => $this->references->nextVersion($style),
                'status' => StoryStyleReferenceStatus::Draft->value,
                'disk' => $stored->disk,
                'path' => $stored->path,
                'original_filename' => null,
                'mime_type' => $stored->mimeType,
                'extension' => $stored->extension,
                'size' => $stored->size,
                'width' => $stored->width,
                'height' => $stored->height,
                'checksum' => $stored->checksum,
                'prompt' => $prompt,
                'provider' => $result->providerKey,
                'model' => $response->model ?? $model,
                'generation' => [
                    'provider' => $result->providerKey,
                    'model' => $response->model ?? $model,
                    'usage' => $response->usage?->toArray(),
                    'duration_ms' => $result->durationMs,
                    'estimated_cost' => $result->estimatedCost,
                ],
            ]);
        } catch (Throwable $exception) {
            $this->store->delete($stored->disk, $stored->path);
            throw $exception;
        }
    }

    public function submitReview(
        Project $project,
        string $referenceUuid,
        User $actor,
        ?string $comment = null,
    ): StoryStyleReference {
        $reference = $this->getForProject($project, $referenceUuid);

        if (! $reference->statusEnum()->isSubmittable()) {
            throw StoryStyleException::invalidTransition($reference->status, 'submitted for review');
        }

        return $this->references->update($reference, [
            'status' => StoryStyleReferenceStatus::PendingReview->value,
            'submitted_at' => now(),
            'review_comment' => $comment,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);
    }

    public function approve(
        Project $project,
        string $referenceUuid,
        User $actor,
        ?string $comment = null,
    ): StoryStyleReference {
        $style = $this->styles->styleForProject($project);
        $reference = $this->references->findByUuidForStyleBible($style, $referenceUuid);

        if ($reference === null) {
            throw StoryException::notFound('Style reference');
        }

        if (! $reference->statusEnum()->isReviewable()) {
            throw StoryStyleException::invalidTransition($reference->status, 'approved');
        }

        return DB::transaction(function () use ($style, $reference, $actor, $comment): StoryStyleReference {
            if ($reference->roleEnum() === StoryStyleReferenceRole::Primary
                && $style->approved_reference_id !== null
                && $style->approved_reference_id !== $reference->id
            ) {
                $previous = StoryStyleReference::query()->find($style->approved_reference_id);
                if ($previous !== null
                    && $previous->statusEnum() === StoryStyleReferenceStatus::Approved
                ) {
                    $this->references->update($previous, [
                        'status' => StoryStyleReferenceStatus::Archived->value,
                    ]);
                }
            }

            $approved = $this->references->update($reference, [
                'status' => StoryStyleReferenceStatus::Approved->value,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'review_comment' => $comment,
            ]);

            $style->approved_reference_id = $approved->id;
            $style->save();

            return $approved->fresh(['styleBible.workspace.project']);
        });
    }

    public function reject(
        Project $project,
        string $referenceUuid,
        User $actor,
        string $comment,
    ): StoryStyleReference {
        $reference = $this->getForProject($project, $referenceUuid);

        if (! $reference->statusEnum()->isReviewable()) {
            throw StoryStyleException::invalidTransition($reference->status, 'rejected');
        }

        return $this->references->update($reference, [
            'status' => StoryStyleReferenceStatus::Rejected->value,
            'reviewed_at' => now(),
            'reviewed_by' => $actor->id,
            'review_comment' => $comment,
        ]);
    }

    public function archive(
        Project $project,
        string $referenceUuid,
        User $actor,
    ): StoryStyleReference {
        $style = $this->styles->styleForProject($project);
        $reference = $this->references->findByUuidForStyleBible($style, $referenceUuid);

        if ($reference === null) {
            throw StoryException::notFound('Style reference');
        }

        if (! $reference->statusEnum()->isArchivable()) {
            throw StoryStyleException::invalidTransition($reference->status, 'archived');
        }

        return DB::transaction(function () use ($style, $reference): StoryStyleReference {
            $archived = $this->references->update($reference, [
                'status' => StoryStyleReferenceStatus::Archived->value,
            ]);

            if ($style->approved_reference_id === $reference->id) {
                $style->approved_reference_id = null;
                $style->save();
            }

            return $archived;
        });
    }

    public function fileBytes(StoryStyleReference $reference): string
    {
        if (! $this->store->exists($reference->disk, $reference->path)) {
            throw StoryStyleException::notReady();
        }

        $bytes = $this->store->get($reference->disk, $reference->path);

        if (! is_string($bytes) || $bytes === '') {
            throw StoryStyleException::notReady();
        }

        return $bytes;
    }

    private function resolveRole(?string $role): StoryStyleReferenceRole
    {
        if ($role === null || $role === '') {
            return StoryStyleReferenceRole::Primary;
        }

        return StoryStyleReferenceRole::tryFrom($role) ?? StoryStyleReferenceRole::Primary;
    }
}
