<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProjectInput;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API representation of a project input (brief).
 *
 * @mixin ProjectInput
 */
class ProjectInputResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'type' => $this->type,
            'deliverable_type' => $this->deliverable_type,
            'platform' => $this->platform,
            'language' => $this->language,
            'topic' => $this->topic,
            'goal' => $this->goal,
            'payload' => $this->payload,
            'source' => $this->source,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
