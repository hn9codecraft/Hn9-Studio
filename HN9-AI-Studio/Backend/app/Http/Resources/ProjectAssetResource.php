<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProjectAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API representation of a studio project asset. Exposes the public UUID.
 * file_url is null until a real URL is supplied — never invented.
 *
 * @mixin ProjectAsset
 */
class ProjectAssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'project_id' => $this->project?->uuid,
            'title' => $this->title,
            'type' => $this->type,
            'source' => $this->source,
            'status' => $this->status,
            'file_url' => $this->file_url,
            'mime_type' => $this->mime_type,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
