<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Story\Models\StoryWorkspace;
use App\Support\StudioWorkflows;
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
        $user = $request->user();
        $approve = $user !== null && $project !== null
            && ($user->isAdmin() || (int) $user->id === (int) $project->user_id);

        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'project' => $project === null ? null : [
                'id' => $project->uuid,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => $project->status,
                'studio_modules' => StudioWorkflows::fromSettings($project->settings),
            ],
            'abilities' => [
                'view' => true,
                'select' => true,
                'approve' => $approve,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
