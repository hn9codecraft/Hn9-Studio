<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GeneratedAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API representation of a generated media asset.
 *
 * @mixin GeneratedAsset
 */
class AssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type,
            'provider' => $this->provider,
            'status' => $this->status,
            'prompt' => $this->prompt,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
