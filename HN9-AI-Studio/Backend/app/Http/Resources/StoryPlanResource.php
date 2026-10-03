<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Story\Models\StoryPlan;
use App\Story\Support\StoryPlanDurationCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoryPlan
 */
class StoryPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $workspace = $this->workspace;
        $project = $workspace?->project;
        $current = $this->relationLoaded('currentVersion') ? $this->currentVersion : null;

        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'idea' => $this->idea,
            'requested_duration_seconds' => $this->requested_duration_seconds,
            'duration_unit' => $this->duration_unit,
            'status' => $this->status,
            'scene_count_estimate' => (int) ceil($this->requested_duration_seconds / StoryPlanDurationCalculator::SCENE_TARGET_SECONDS),
            'current_version' => $current === null ? null : (new StoryPlanVersionResource($current))->resolve(),
            'production_plan' => $this->whenLoaded(
                'currentProductionPlan',
                fn (): ?array => $this->currentProductionPlan === null
                    ? null
                    : (new StoryProductionPlanResource($this->currentProductionPlan))->resolve($request),
            ),
            'workspace' => $workspace === null ? null : [
                'id' => $workspace->uuid,
                'status' => $workspace->status,
            ],
            'project' => $project === null ? null : [
                'id' => $project->uuid,
                'name' => $project->name,
                'status' => $project->status,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
