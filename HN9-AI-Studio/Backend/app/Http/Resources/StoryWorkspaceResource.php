<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Story\Models\StoryWorkspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoryWorkspace
 */
class StoryWorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $project = $this->project;

        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'project' => $project === null ? null : [
                'id' => $project->uuid,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => $project->status,
            ],
            'abilities' => [
                'view' => true,
                'select' => true,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
