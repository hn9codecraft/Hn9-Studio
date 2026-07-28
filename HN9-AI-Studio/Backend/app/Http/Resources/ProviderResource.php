<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AiProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API representation of an AI provider registry entry. Exposes definition and
 * capabilities only — never credential/setting values.
 *
 * @mixin AiProvider
 */
class ProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'category' => $this->category,
            'status' => $this->status,
            'base_url' => $this->base_url,
            'priority' => $this->priority,
            'capabilities' => $this->capabilities,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
