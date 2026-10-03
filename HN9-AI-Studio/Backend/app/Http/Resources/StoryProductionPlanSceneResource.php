<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Story\Models\StoryProductionPlanScene;
use App\Story\Models\StoryProductionUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoryProductionPlanScene
 */
class StoryProductionPlanSceneResource extends JsonResource
{
    public function __construct(StoryProductionPlanScene $resource, private readonly int $unitSeconds)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $scene = $this->relationLoaded('scene') ? $this->scene : null;
        $units = $this->relationLoaded('units') ? $this->units : null;

        return [
            'id' => $this->uuid,
            'scene_id' => $scene?->uuid,
            'title' => $scene?->title,
            'sequence' => $this->sequence,
            'start_second' => $this->start_second,
            'duration_seconds' => $this->duration_seconds,
            'end_second' => $this->endSecond(),
            'unit_count' => $units?->count(),
            'units' => $units?->map(fn (StoryProductionUnit $unit): array => [
                'id' => $unit->uuid,
                'sequence' => $unit->sequence,
                'start_second' => $unit->start_second,
                'duration_seconds' => $unit->duration_seconds,
                'end_second' => $unit->endSecond(),
                'kind' => $unit->duration_seconds < $this->unitSeconds ? 'remainder' : 'standard',
                'selected_version_id' => $unit->relationLoaded('selectedVersion') ? $unit->selectedVersion?->uuid : null,
            ])->values()->all(),
        ];
    }
}
