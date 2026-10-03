<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionPlanScene;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoryProductionPlan
 */
class StoryProductionPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $scenes = $this->relationLoaded('scenes') ? $this->scenes : null;

        return [
            'id' => $this->uuid,
            'revision' => $this->revision,
            'status' => $this->status,
            'is_current' => $this->isCurrent(),
            'unit_seconds' => $this->unit_seconds,
            'total_duration_seconds' => $this->total_duration_seconds,
            'scene_count' => $this->scenes_count ?? $scenes?->count(),
            'unit_count' => $this->units_count
                ?? $scenes?->sum(static fn (StoryProductionPlanScene $scene): int => $scene->units->count()),
            'story_plan' => $this->whenLoaded('storyPlan', fn (): array => [
                'id' => $this->storyPlan->uuid,
                'title' => $this->storyPlan->title,
            ]),
            'source_version' => $this->whenLoaded('sourceVersion', fn (): array => [
                'id' => $this->sourceVersion->uuid,
                'version' => $this->sourceVersion->version,
            ]),
            'reel' => $this->whenLoaded('reel', fn (): array => [
                'id' => $this->reel->uuid,
                'title' => $this->reel->title,
            ]),
            'previous_plan_id' => $this->whenLoaded('previousPlan', fn (): ?string => $this->previousPlan?->uuid),
            'next_plan_id' => $this->whenLoaded('nextPlan', fn (): ?string => $this->nextPlan?->uuid),
            'superseded_at' => $this->superseded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'scenes' => $this->when($scenes !== null, fn (): array => $scenes
                ->map(fn (StoryProductionPlanScene $scene): array => (new StoryProductionPlanSceneResource($scene, $this->unit_seconds))->resolve($request))
                ->values()
                ->all()),
        ];
    }
}
