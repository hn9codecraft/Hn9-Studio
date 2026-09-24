<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ProjectStatus;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Studio video. Public UUID only. Stored files are served by an authenticated
 * route. Provider job IDs and temporary provider URIs are not returned.
 *
 * @mixin Video
 */
class VideoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'project_id' => $this->project?->uuid,
            'title' => $this->title,
            'prompt' => $this->prompt,
            'negative_prompt' => $this->negative_prompt,
            'aspect_ratio' => $this->aspect_ratio,
            'duration' => $this->duration,
            'status' => $this->status,
            'source' => $this->source ?: 'manual',
            'provider' => $this->provider,
            'provider_job_id' => null,
            'output_url' => null,
            'has_file' => $this->relationLoaded('file') && $this->file !== null,
            'file' => $this->filePayload(),
            'script_id' => $this->script?->uuid,
            'image_id' => $this->image?->uuid,
            'parent_video_id' => $this->parentVideo?->uuid,
            'generated_asset_id' => $this->generatedAsset?->uuid,
            'metadata' => $this->publicMetadata(),
            'generation' => $this->publicGeneration(),
            'latest_review' => $this->latestReviewPayload(),
            'latest_rework' => $this->latestReworkPayload(),
            'capabilities' => $this->capabilities($request),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function filePayload(): ?array
    {
        if (! $this->relationLoaded('file') || $this->file === null) {
            return null;
        }

        $meta = is_array($this->file->meta) ? $this->file->meta : [];

        return [
            'id' => $this->file->uuid,
            'mime_type' => $this->file->mime_type,
            'extension' => $this->file->extension,
            'size' => $this->file->size,
            'duration' => $meta['duration'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publicMetadata(): ?array
    {
        if (! is_array($this->metadata)) {
            return null;
        }

        return array_intersect_key($this->metadata, array_flip([
            'mime_type',
            'extension',
            'size',
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publicGeneration(): ?array
    {
        if (! is_array($this->generation)) {
            return null;
        }

        return array_intersect_key($this->generation, array_flip([
            'provider',
            'model',
            'prompt',
            'aspect_ratio',
            'duration',
            'resolution',
            'mode',
            'parent_video_id',
            'script_id',
            'image_id',
            'generated_asset_id',
            'usage',
            'cost',
            'error',
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestReviewPayload(): ?array
    {
        if (! $this->relationLoaded('latestReviewEvent') || $this->latestReviewEvent === null) {
            return null;
        }

        return (new VideoReviewEventResource($this->latestReviewEvent))->resolve();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestReworkPayload(): ?array
    {
        if (! $this->relationLoaded('latestReworkEvent') || $this->latestReworkEvent === null) {
            return null;
        }

        return (new VideoReviewEventResource($this->latestReworkEvent))->resolve();
    }

    /**
     * @return array<string, bool>
     */
    private function capabilities(Request $request): array
    {
        $user = $request->user();
        $projectEditable = $this->projectIsEditable();

        if ($user === null) {
            return [
                'edit' => false,
                'submit' => false,
                'approve' => false,
                'request_rework' => false,
                'regenerate' => false,
            ];
        }

        $canUpdate = Gate::forUser($user)->allows('update', $this->resource);
        $canSubmit = Gate::forUser($user)->allows('submit', $this->resource);
        $canReview = Gate::forUser($user)->allows('review', $this->resource);

        return [
            'edit' => $canUpdate && $this->allowsContentEdit() && $projectEditable,
            'submit' => $canSubmit && $this->isSubmittable() && $projectEditable,
            'approve' => $canReview && $this->isReviewable() && $projectEditable,
            'request_rework' => $canReview && $this->isReviewable() && $projectEditable,
            'regenerate' => $canUpdate && $this->allowsRegeneration() && $projectEditable && $this->source === 'ai',
        ];
    }

    private function projectIsEditable(): bool
    {
        $project = $this->project;

        if ($project === null) {
            return false;
        }

        $status = ProjectStatus::tryFrom((string) $project->status);

        return $status !== null && $status->isEditable();
    }
}
