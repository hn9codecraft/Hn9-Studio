<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Story\Models\StoryCharacterReference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoryCharacterReference
 *
 * Private storage disk/path are intentionally omitted.
 */
class StoryCharacterReferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $character = $this->character;
        $isApprovedCurrent = $character !== null
            && $character->approved_reference_id !== null
            && (int) $character->approved_reference_id === (int) $this->id;

        return [
            'id' => $this->uuid,
            'version' => $this->version,
            'status' => $this->status,
            'source' => $this->source,
            'role' => $this->role,
            'is_approved_current' => $isApprovedCurrent,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'width' => $this->width,
            'height' => $this->height,
            'size' => $this->size,
            'prompt' => $this->prompt,
            'provider' => $this->provider,
            'model' => $this->model,
            'generation' => $this->safeGeneration(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_comment' => $this->review_comment,
            'character' => $character === null ? null : [
                'id' => $character->uuid,
                'name' => $character->name,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeGeneration(): ?array
    {
        $generation = $this->generation;

        if (! is_array($generation)) {
            return null;
        }

        $blocked = ['api_key', 'authorization', 'token', 'secret', 'password', 'path', 'disk'];
        $clean = [];

        foreach ($generation as $key => $value) {
            $lower = strtolower((string) $key);
            foreach ($blocked as $needle) {
                if (str_contains($lower, $needle)) {
                    continue 2;
                }
            }
            if (is_scalar($value) || $value === null || is_array($value)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
