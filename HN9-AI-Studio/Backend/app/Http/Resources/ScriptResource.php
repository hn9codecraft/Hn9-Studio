<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ProjectStatus;
use App\Models\Script;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * API representation of a studio script. Exposes the public UUID, never the
 * internal auto-increment id.
 *
 * @mixin Script
 */
class ScriptResource extends JsonResource
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
            'body' => $this->body,
            'status' => $this->status,
            'source' => $this->source ?: 'manual',
            'generated_content_id' => $this->generatedContent?->uuid,
            'parent_script_id' => $this->parentScript?->uuid,
            'generation' => $this->publicGeneration(),
            'latest_review' => $this->latestReviewPayload(),
            'latest_rework' => $this->latestReworkPayload(),
            'capabilities' => $this->capabilities($request),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
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
            'edit' => $canUpdate && $this->allowsContentEdit(),
            'submit' => $canSubmit && $this->isSubmittable() && $projectEditable,
            'approve' => $canReview && $this->isReviewable() && $projectEditable,
            'request_rework' => $canReview && $this->isReviewable() && $projectEditable,
            'regenerate' => $canUpdate && $this->allowsRegeneration() && $projectEditable,
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

    /**
     * @return array<string, mixed>|null
     */
    private function latestReviewPayload(): ?array
    {
        if (! $this->relationLoaded('latestReviewEvent') || $this->latestReviewEvent === null) {
            return null;
        }

        return (new ScriptReviewEventResource($this->latestReviewEvent))->resolve();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestReworkPayload(): ?array
    {
        if (! $this->relationLoaded('latestReworkEvent') || $this->latestReworkEvent === null) {
            return null;
        }

        return (new ScriptReviewEventResource($this->latestReworkEvent))->resolve();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publicGeneration(): ?array
    {
        $generation = $this->generation;

        if (! is_array($generation) || $generation === []) {
            return null;
        }

        return [
            'deliverable_type' => $generation['deliverable_type'] ?? 'script',
            'platform' => $generation['platform'] ?? null,
            'language' => $generation['language'] ?? null,
            'topic' => $generation['topic'] ?? null,
            'goal' => $generation['goal'] ?? null,
            'payload' => is_array($generation['payload'] ?? null) ? $generation['payload'] : [],
            'provider' => $generation['provider'] ?? null,
            'generated_content_id' => $generation['generated_content_id'] ?? null,
            'prompt_execution_id' => $generation['prompt_execution_id'] ?? null,
            'parent_script_id' => $generation['parent_script_id'] ?? null,
        ];
    }
}
