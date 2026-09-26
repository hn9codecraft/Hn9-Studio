<?php

declare(strict_types=1);

namespace App\Story\Video;

use App\Models\Project;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoInputType;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryStyleReference;
use App\Story\Models\StoryVideoGenerationJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Resolves generation inputs to project-owned story references.
 * Provider adapters receive a storage location only after this check.
 */
final class StoryVideoAssetResolver
{
    public function resolve(Project $project, StoryVideoGenerationRequest $request): StoryVideoGenerationRequest
    {
        $input = match ($request->capability) {
            StoryVideoCapability::ImageToVideo => $this->characterImage($project, $request),
            StoryVideoCapability::ReferenceToVideo => $this->referenceImage($project, $request),
            StoryVideoCapability::VideoEdit, StoryVideoCapability::VideoExtend => $this->storedSceneVideo($project, $request),
            default => null,
        };

        if ($input === null) {
            return $request;
        }

        $inputs = [];
        $replaced = false;
        foreach ($request->inputs as $existing) {
            if (! $replaced && $existing->type === $input->type && $existing->assetId === $input->assetId) {
                $inputs[] = $input;
                $replaced = true;
                continue;
            }
            $inputs[] = $existing;
        }

        return new StoryVideoGenerationRequest(
            capability: $request->capability,
            workspaceUuid: $request->workspaceUuid,
            reelUuid: $request->reelUuid,
            sceneUuid: $request->sceneUuid,
            prompt: $request->prompt,
            negativePrompt: $request->negativePrompt,
            durationSeconds: $request->durationSeconds,
            aspectRatio: $request->aspectRatio,
            resolution: $request->resolution,
            audioRequested: $request->audioRequested,
            inputs: $inputs,
            preferredProvider: $request->preferredProvider,
            preferredModel: $request->preferredModel,
            idempotencyKey: $request->idempotencyKey,
            metadata: $request->metadata,
            extensions: $request->extensions,
        );
    }

    private function storedSceneVideo(Project $project, StoryVideoGenerationRequest $request): StoryVideoInput
    {
        $input = $this->requireInput($request, StoryVideoInputType::Video, 'A stored scene video is required.');
        $disk = $input->metadata['disk'] ?? null;
        $path = $input->metadata['path'] ?? null;
        $mime = $input->metadata['mime'] ?? null;
        if ($disk !== 'videos' || ! is_string($path) || $path === '' || str_contains($path, '..') || str_contains($path, '://')) {
            throw StoryVideoEngineException::invalidInput('A stored scene video is required.');
        }

        $owned = StoryVideoGenerationJob::query()
            ->whereHas('workspace', static function ($query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->get()
            ->contains(static function (StoryVideoGenerationJob $job) use ($disk, $path): bool {
                $storage = $job->provider_metadata['storage'] ?? null;

                return is_array($storage)
                    && ($storage['disk'] ?? null) === $disk
                    && ($storage['path'] ?? null) === $path;
            });

        if (! $owned || ! Storage::disk($disk)->exists($path)) {
            throw StoryVideoEngineException::invalidInput('A stored scene video is required.');
        }

        return new StoryVideoInput(
            type: StoryVideoInputType::Video,
            assetId: $input->assetId,
            metadata: [
                'disk' => $disk,
                'path' => $path,
                'mime' => is_string($mime) && $mime !== '' ? $mime : 'video/mp4',
            ],
            role: $input->role,
            order: $input->order,
        );
    }

    private function characterImage(Project $project, StoryVideoGenerationRequest $request): StoryVideoInput
    {
        $input = $this->requireInput($request, StoryVideoInputType::Image, 'An owned character image is required.');
        $reference = $this->characterReference($project, (string) $input->assetId);

        return $this->storedInput($input, $reference->disk, $reference->path, $reference->mime_type, $reference->role);
    }

    private function referenceImage(Project $project, StoryVideoGenerationRequest $request): StoryVideoInput
    {
        $input = $this->requireInput(
            $request,
            StoryVideoInputType::ReferenceImage,
            'An owned character or style reference is required.',
        );
        $assetId = (string) $input->assetId;
        $character = $this->findCharacterReference($project, $assetId);
        if ($character instanceof StoryCharacterReference) {
            return $this->storedInput($input, $character->disk, $character->path, $character->mime_type, $character->role);
        }

        $style = $this->findStyleReference($project, $assetId);
        if ($style instanceof StoryStyleReference) {
            return $this->storedInput($input, $style->disk, $style->path, $style->mime_type, $style->role);
        }

        throw StoryVideoEngineException::invalidInput('The reference does not belong to this project.');
    }

    private function characterReference(Project $project, string $assetId): StoryCharacterReference
    {
        $reference = $this->findCharacterReference($project, $assetId);
        if (! $reference instanceof StoryCharacterReference) {
            throw StoryVideoEngineException::invalidInput('The reference does not belong to this project.');
        }

        return $reference;
    }

    private function findCharacterReference(Project $project, string $assetId): ?StoryCharacterReference
    {
        if (! Str::isUuid($assetId)) {
            return null;
        }

        return StoryCharacterReference::query()
            ->where('uuid', $assetId)
            ->whereHas('character.workspace', static function ($query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->first();
    }

    private function findStyleReference(Project $project, string $assetId): ?StoryStyleReference
    {
        if (! Str::isUuid($assetId)) {
            return null;
        }

        return StoryStyleReference::query()
            ->where('uuid', $assetId)
            ->whereHas('styleBible.workspace', static function ($query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->first();
    }

    private function requireInput(
        StoryVideoGenerationRequest $request,
        StoryVideoInputType $type,
        string $message,
    ): StoryVideoInput {
        foreach ($request->inputs as $input) {
            if ($input->type === $type) {
                return $input;
            }
        }

        throw StoryVideoEngineException::invalidInput($message);
    }

    private function storedInput(
        StoryVideoInput $input,
        string $disk,
        string $path,
        ?string $mime,
        ?string $role,
    ): StoryVideoInput {
        if ($disk !== 'images' || $path === '' || str_contains($path, '..') || str_contains($path, '://')) {
            throw StoryVideoEngineException::invalidInput('The reference file is not stored.');
        }

        if (! Storage::disk($disk)->exists($path)) {
            throw StoryVideoEngineException::invalidInput('The reference file is not stored.');
        }

        return new StoryVideoInput(
            type: $input->type,
            assetId: $input->assetId,
            metadata: [
                'disk' => $disk,
                'path' => $path,
                'mime' => is_string($mime) && $mime !== '' ? $mime : 'image/png',
            ],
            role: $input->role ?? $role,
            order: $input->order,
        );
    }
}
