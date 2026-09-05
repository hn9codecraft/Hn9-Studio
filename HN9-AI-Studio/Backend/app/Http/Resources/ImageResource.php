<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API representation of a studio image request. Exposes the public UUID, never
 * the internal auto-increment id. output_url is null until a real provider writes one.
 *
 * @mixin Image
 */
class ImageResource extends JsonResource
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
            'prompt' => $this->prompt,
            'negative_prompt' => $this->negative_prompt,
            'aspect_ratio' => $this->aspect_ratio,
            'status' => $this->status,
            'provider' => $this->provider,
            'provider_job_id' => $this->provider_job_id,
            'output_url' => $this->output_url,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
