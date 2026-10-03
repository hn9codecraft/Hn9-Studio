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
use App\Story\Contracts\StoryCharacterReferenceRepositoryInterface;
use App\Story\Contracts\StoryCharacterReferenceServiceInterface;
use App\Story\Contracts\StoryCharacterServiceInterface;
use App\Story\Enums\StoryCharacterReferenceRole;
use App\Story\Enums\StoryCharacterReferenceSource;
use App\Story\Enums\StoryCharacterReferenceStatus;
use App\Story\Exceptions\StoryCharacterException;
use App\Story\Exceptions\StoryException;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use App\Story\Support\CharacterReferencePromptBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Character reference lifecycle. Image generation goes through ProviderDispatcherInterface only.
 */
final readonly class StoryCharacterReferenceService implements StoryCharacterReferenceServiceInterface
{
    public function __construct(
        private StoryCharacterServiceInterface $characters,
        private StoryCharacterReferenceRepositoryInterface $references,
        private CharacterReferenceBinaryStore $store,
        private CharacterReferencePromptBuilder $promptBuilder,
        private ProviderDispatcherInterface $dispatcher,
        private StoryGenerationAttemptRecorder $attempts,
    ) {}

    public function listForCharacter(Project $project, string $characterUuid): Collection
    {
        $character = $this->characters->getForProject($project, $characterUuid);

        return $this->references->listForCharacter($character);
    }

    public function getForCharacter(Project $project, string $characterUuid, string $referenceUuid): StoryCharacterReference
    {
        $character = $this->characters->getForProject($project, $characterUuid);
        $reference = $this->references->findByUuidForCharacter($character, $referenceUuid);

        if ($reference === null) {
            throw StoryException::notFound('Character reference');
        }

        return $reference;
    }

    public function upload(Project $project, string $characterUuid, UploadedFile $file, ?string $role = null): StoryCharacterReference
    {
        $character = $this->requireEditableCharacter($project, $characterUuid);
        $stored = $this->store->storeUpload($project, $character, $file);

        try {
            return $this->references->create($character, [
                'source' => StoryCharacterReferenceSource::Uploaded->value,
                'role' => $this->resolveRole($role)->value,
                'version' => $this->references->nextVersion($character),
                'status' => StoryCharacterReferenceStatus::Draft->value,
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

    public function generate(Project $project, string $characterUuid, array $options = []): StoryCharacterReference
    {
        $character = $this->requireEditableCharacter($project, $characterUuid);
        $prompt = $this->promptBuilder->build($character);

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
            $this->attempts->recordFailure($project, StoryGenerationAttemptRecorder::KIND_CHARACTER_IMAGE, 'image', $exception);
            throw StoryCharacterException::generationFailed($exception->getMessage());
        } catch (Throwable $exception) {
            $this->attempts->recordFailure($project, StoryGenerationAttemptRecorder::KIND_CHARACTER_IMAGE, 'image', $exception);
            throw StoryCharacterException::generationFailed(
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Image generation failed.',
            );
        }

        $response = $result->response;

        if (! $response instanceof ImageResponse) {
            throw StoryCharacterException::generationFailed('Image generation returned an unexpected response.');
        }

        try {
            $stored = $this->store->storeGenerated($project, $character, $response);
        } catch (StoryCharacterException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw StoryCharacterException::generationFailed('Generated image could not be stored.');
        }

        try {
            return $this->references->create($character, [
                'source' => StoryCharacterReferenceSource::Generated->value,
                'role' => $this->resolveRole(is_string($options['role'] ?? null) ? $options['role'] : null)->value,
                'version' => $this->references->nextVersion($character),
                'status' => StoryCharacterReferenceStatus::Draft->value,
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
        string $characterUuid,
        string $referenceUuid,
        User $actor,
        ?string $comment = null,
    ): StoryCharacterReference {
        $reference = $this->getForCharacter($project, $characterUuid, $referenceUuid);

        if (! $reference->statusEnum()->isSubmittable()) {
            throw StoryCharacterException::invalidTransition($reference->status, 'submitted for review');
        }

        return $this->references->update($reference, [
            'status' => StoryCharacterReferenceStatus::PendingReview->value,
            'submitted_at' => now(),
            'review_comment' => $comment,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);
    }

    public function approve(
        Project $project,
        string $characterUuid,
        string $referenceUuid,
        User $actor,
        ?string $comment = null,
    ): StoryCharacterReference {
        $character = $this->characters->getForProject($project, $characterUuid);
        $reference = $this->references->findByUuidForCharacter($character, $referenceUuid);

        if ($reference === null) {
            throw StoryException::notFound('Character reference');
        }

        if (! $reference->statusEnum()->isReviewable()) {
            throw StoryCharacterException::invalidTransition($reference->status, 'approved');
        }

        return DB::transaction(function () use ($character, $reference, $actor, $comment): StoryCharacterReference {
            // Rule: one active approved primary reference per character.
            if ($reference->roleEnum() === StoryCharacterReferenceRole::Primary
                && $character->approved_reference_id !== null
                && $character->approved_reference_id !== $reference->id
            ) {
                $previous = StoryCharacterReference::query()->find($character->approved_reference_id);
                if ($previous !== null
                    && $previous->statusEnum() === StoryCharacterReferenceStatus::Approved
                ) {
                    $this->references->update($previous, [
                        'status' => StoryCharacterReferenceStatus::Archived->value,
                    ]);
                }
            }

            $approved = $this->references->update($reference, [
                'status' => StoryCharacterReferenceStatus::Approved->value,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
                'review_comment' => $comment,
            ]);

            $character->approved_reference_id = $approved->id;
            $character->save();

            return $approved->fresh(['character.workspace.project']);
        });
    }

    public function reject(
        Project $project,
        string $characterUuid,
        string $referenceUuid,
        User $actor,
        string $comment,
    ): StoryCharacterReference {
        $reference = $this->getForCharacter($project, $characterUuid, $referenceUuid);

        if (! $reference->statusEnum()->isReviewable()) {
            throw StoryCharacterException::invalidTransition($reference->status, 'rejected');
        }

        return $this->references->update($reference, [
            'status' => StoryCharacterReferenceStatus::Rejected->value,
            'reviewed_at' => now(),
            'reviewed_by' => $actor->id,
            'review_comment' => $comment,
        ]);
    }

    public function archive(
        Project $project,
        string $characterUuid,
        string $referenceUuid,
        User $actor,
    ): StoryCharacterReference {
        $character = $this->characters->getForProject($project, $characterUuid);
        $reference = $this->references->findByUuidForCharacter($character, $referenceUuid);

        if ($reference === null) {
            throw StoryException::notFound('Character reference');
        }

        if (! $reference->statusEnum()->isArchivable()) {
            throw StoryCharacterException::invalidTransition($reference->status, 'archived');
        }

        return DB::transaction(function () use ($character, $reference): StoryCharacterReference {
            $archived = $this->references->update($reference, [
                'status' => StoryCharacterReferenceStatus::Archived->value,
            ]);

            if ($character->approved_reference_id === $reference->id) {
                $character->approved_reference_id = null;
                $character->save();
            }

            return $archived;
        });
    }

    public function fileBytes(StoryCharacterReference $reference): string
    {
        if (! $this->store->exists($reference->disk, $reference->path)) {
            throw StoryCharacterException::notReady();
        }

        $bytes = $this->store->get($reference->disk, $reference->path);

        if (! is_string($bytes) || $bytes === '') {
            throw StoryCharacterException::notReady();
        }

        return $bytes;
    }

    private function requireEditableCharacter(Project $project, string $characterUuid): StoryCharacter
    {
        $character = $this->characters->getForProject($project, $characterUuid);

        if (! $character->allowsEdit()) {
            throw StoryCharacterException::archived();
        }

        return $character;
    }

    private function resolveRole(?string $role): StoryCharacterReferenceRole
    {
        if ($role === null || $role === '') {
            return StoryCharacterReferenceRole::Primary;
        }

        return StoryCharacterReferenceRole::tryFrom($role) ?? StoryCharacterReferenceRole::Primary;
    }

}
