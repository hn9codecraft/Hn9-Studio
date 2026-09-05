<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ProjectActivityModule;
use App\Models\ActivityLog;
use App\Models\Image;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\Script;
use App\Models\Video;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe studio representation of an activity_logs row.
 * Omits IP address, user agent, integer IDs, and raw properties.
 *
 * @mixin ActivityLog
 */
class ProjectActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $module = ProjectActivityModule::fromAction((string) $this->action)
            ?? ProjectActivityModule::fromSubject($this->subject);

        $subject = $this->subject;

        return [
            'id' => $this->uuid,
            'action' => $this->action,
            'module' => $module?->value,
            'description' => $this->description,
            'actor' => $this->user ? [
                'name' => $this->user->name,
            ] : null,
            'subject' => $subject instanceof Model ? [
                'type' => $module?->value,
                'id' => $subject->uuid ?? null,
                'title' => $this->subjectTitle($subject),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function subjectTitle(Model $subject): ?string
    {
        if ($subject instanceof Project) {
            return $subject->name;
        }

        if ($subject instanceof Script || $subject instanceof Image || $subject instanceof Video || $subject instanceof ProjectAsset) {
            return $subject->title;
        }

        return null;
    }
}
