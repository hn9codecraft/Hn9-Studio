<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Story\Models\StoryBible;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoryBible
 */
class StoryBibleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $workspace = $this->workspace;
        $project = $workspace?->project;

        return [
            'id' => $this->uuid,
            'configured' => $this->isConfigured(),
            'concept' => $this->concept,
            'genre' => $this->genre,
            'audience' => $this->audience,
            'language' => $this->language,
            'tone' => $this->tone,
            'world' => $this->world,
            'location' => $this->location,
            'time_period' => $this->time_period,
            'narrative_style' => $this->narrative_style,
            'video_style' => $this->video_style,
            'aspect_ratio' => $this->aspect_ratio,
            'default_duration' => $this->default_duration,
            'audio_defaults' => $this->audioDefaults(),
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
