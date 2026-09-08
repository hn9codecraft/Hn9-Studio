<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Image;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\Script;
use App\Models\Video;
use Illuminate\Http\Request;

/**
 * Studio activity row for the dashboard feed. Adds the parent project's
 * public UUID so the UI can link without exposing integer IDs.
 *
 * @mixin \App\Models\ActivityLog
 */
class DashboardActivityResource extends ProjectActivityResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = parent::toArray($request);
        $payload['project'] = $this->parentProject();

        return $payload;
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private function parentProject(): ?array
    {
        $subject = $this->subject;

        if ($subject instanceof Project) {
            return [
                'id' => $subject->uuid,
                'name' => $subject->name,
            ];
        }

        if (
            $subject instanceof Script
            || $subject instanceof Image
            || $subject instanceof Video
            || $subject instanceof ProjectAsset
        ) {
            $project = $subject->project;

            if ($project instanceof Project) {
                return [
                    'id' => $project->uuid,
                    'name' => $project->name,
                ];
            }
        }

        return null;
    }
}
