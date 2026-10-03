<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Models\StoryVideoGenerationJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public generation state. Provider credentials, raw responses and internal ids stay out.
 *
 * @mixin StoryVideoGenerationJob
 */
class StoryProductionUnitGenerationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $storage = $this->provider_metadata['storage'] ?? null;
        $available = $this->statusEnum() === StoryVideoJobStatus::Completed
            && ($this->provider_metadata['unit_output_checked'] ?? false) === true
            && is_array($storage)
            && ($storage['disk'] ?? null) === 'videos'
            && is_string($storage['path'] ?? null)
            && $storage['path'] !== '';

        return [
            'id' => $this->uuid,
            'unit_id' => $this->productionUnit?->uuid,
            'capability' => $this->capability,
            'status' => $this->status,
            'output_available' => $available,
            'timed_out' => (bool) $this->timed_out,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
