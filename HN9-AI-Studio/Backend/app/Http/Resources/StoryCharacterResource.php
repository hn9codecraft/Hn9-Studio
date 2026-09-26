<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Story\Models\StoryCharacter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoryCharacter
 */
class StoryCharacterResource extends JsonResource
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
            'name' => $this->name,
            'short_description' => $this->short_description,
            'age' => $this->age,
            'gender_presentation' => $this->gender_presentation,
            'appearance' => $this->appearance,
            'face_description' => $this->face_description,
            'hair' => $this->hair,
            'clothing' => $this->clothing,
            'personality' => $this->personality,
            'voice_description' => $this->voice_description,
            'special_details' => $this->special_details,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'approved_reference' => $approved === null
                ? null
                : [
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
