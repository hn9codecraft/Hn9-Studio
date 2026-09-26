<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Story\Models\StoryStyleBible;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoryStyleBible
 */
class StoryStyleBibleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $workspace = $this->workspace;
        $project = $workspace?->project;
        $approved = $this->relationLoaded('approvedReference')
            ? $this->approvedReference
            : null;

        return [
            'id' => $this->uuid,
            'configured' => $this->isConfigured(),
            'visual_style' => $this->visual_style,
            'animation_style' => $this->animation_style,
            'lighting' => $this->lighting,
            'camera_style' => $this->camera_style,
            'color_direction' => $this->color_direction,
            'environment_style' => $this->environment_style,
            'mood' => $this->mood,
            'rendering_style' => $this->rendering_style,
            'visual_quality' => $this->visual_quality,
            'art_direction_notes' => $this->art_direction_notes,
            'aspect_ratio' => $this->aspect_ratio,
            'approved_reference' => $approved === null ? null : [
                'id' => $approved->uuid,
                'version' => $approved->version,
                'status' => $approved->status,
                'source' => $approved->source,
                'role' => $approved->role,
                'mime_type' => $approved->mime_type,
                'width' => $approved->width,
                'height' => $approved->height,
                'size' => $approved->size,
                'created_at' => $approved->created_at?->toIso8601String(),
            ],
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
