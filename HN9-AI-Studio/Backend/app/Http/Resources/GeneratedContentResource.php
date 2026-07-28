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
            'version' => $this->version,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
