<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GeneratedContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API representation of a piece of generated textual content.
 *
 * @mixin GeneratedContent
 */
class GeneratedContentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type,
            'channel' => $this->channel,
            'language' => $this->language,
            'title' => $this->title,
            'body' => $this->body,
            'structured' => $this->structured,
            'status' => $this->status,
            'is_favorite' => (bool) $this->is_favorite,
            'version' => $this->version,
            // Surfaced because both are exposed list filters, and both live
            // inside the payloads the orchestrator records rather than in
            // columns of their own.
            'provider' => data_get($this->metadata, 'provider'),
            'template_key' => data_get($this->structured, 'template_key'),
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->uuid,
                'name' => $this->project->name,
            ]),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
