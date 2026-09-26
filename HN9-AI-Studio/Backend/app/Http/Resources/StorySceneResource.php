<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Story\Models\StoryScene;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoryScene
 */
class StorySceneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sourceVersion = $this->relationLoaded('sourcePlanVersion') ? $this->sourcePlanVersion : null;

        return [
            'id' => $this->uuid,
            'sequence' => $this->sequence,
            'title' => $this->title,
            'duration_seconds' => $this->duration_seconds,
            'start_second' => $this->start_second,
            'end_second' => $this->end_second,
            'status' => $this->status,
            'story' => $this->story,
            'characters' => $this->characters ?? [],
            'location' => $this->location,
            'dialogue' => $this->dialogue ?? [],
            'narration' => $this->narration,
            'visual_prompt' => $this->visual_prompt,
            'motion_prompt' => $this->motion_prompt,
            'audio_direction' => $this->audio_direction,
            'continuity' => $this->continuity ?? [
                'previous_scene' => null,
                'next_scene' => null,
                'character_state' => null,
                'environment_state' => null,
            ],
            'source_plan_version' => $sourceVersion === null ? null : [
                'id' => $sourceVersion->uuid,
                'version' => $sourceVersion->version,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
