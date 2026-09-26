<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Story\Enums\StorySceneStatus;
use App\Story\Models\StoryReel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoryReel
 */
class StoryReelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $workspace = $this->workspace;
        $project = $workspace?->project;
        $scenes = $this->relationLoaded('scenes')
            ? $this->scenes->where('status', '!=', StorySceneStatus::Archived->value)->values()
            : collect();
        $sourcePlan = $this->relationLoaded('sourcePlan') ? $this->sourcePlan : null;
        $sourceVersion = $this->relationLoaded('sourcePlanVersion') ? $this->sourcePlanVersion : null;

        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'sequence' => $this->sequence,
            'status' => $this->status,
            'total_duration_seconds' => $this->total_duration_seconds,
            'scene_count' => $scenes->count(),
            'scenes' => StorySceneResource::collection($scenes),
            'source_plan' => $sourcePlan === null ? null : [
                'id' => $sourcePlan->uuid,
                'title' => $sourcePlan->title,
            ],
            'source_plan_version' => $sourceVersion === null ? null : [
                'id' => $sourceVersion->uuid,
                'version' => $sourceVersion->version,
                'status' => $sourceVersion->status,
            ],
            'workspace' => $workspace === null ? null : [
                'id' => $workspace->uuid,
                'status' => $workspace->status,
            ],
            'project' => $project === null ? null : [
                'id' => $project->uuid,
                'name' => $project->name,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
