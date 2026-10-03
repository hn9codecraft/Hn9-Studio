<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Story\Models\StoryProductionPlanScene;
use App\Story\Models\StoryProductionUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

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
            'production' => $this->productionSummary($units),
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

    /**
     * Counts for the scene card. Clip slots stay on `units`; this does not split duration.
     *
     * @param  Collection<int, StoryProductionUnit>|null  $units
     * @return array<string, int|string>
     */
    private function productionSummary($units): array
    {
        $clipCount = $units?->count() ?? 0;
        $selected = 0;
        $approved = 0;
        $review = 0;
        $changes = 0;
        $generating = 0;
        $failed = 0;
        foreach ($units ?? [] as $unit) {
            if ($unit->selectedVersion !== null && $unit->selectedVersion->review_status === 'approved') {
                $selected++;
            }
            $statuses = $unit->relationLoaded('versions') ? $unit->versions->pluck('review_status') : collect();
            if ($statuses->contains('approved')) {
                $approved++;
            }
            if ($statuses->contains('pending_review')) {
                $review++;
            }
            if ($statuses->contains('needs_rework')) {
                $changes++;
            }
            if ($unit->generation_active) {
                $generating++;
            }
            if ($unit->generation_failed && $statuses->isEmpty()) {
                $failed++;
            }
        }

        return [
            'clip_count' => $clipCount,
            'selected_count' => $selected,
            'approved_count' => $approved,
            'review_count' => $review,
            'changes_count' => $changes,
            'generating_count' => $generating,
            'failed_count' => $failed,
            'scene_video' => $this->sceneVideoStatus(),
        ];
    }

    private function sceneVideoStatus(): string
    {
        $rows = $this->relationLoaded('assemblies') ? $this->assemblies : collect();
        if ($rows->contains(static fn ($row): bool => in_array((string) $row->status, ['queued', 'processing', 'submitted'], true))) {
            return 'building';
        }
        if ($rows->contains(static fn ($row): bool => (string) $row->status === 'completed')) {
            return 'ready';
        }
        if ($rows->contains(static fn ($row): bool => (string) $row->status === 'failed')) {
            return 'failed';
        }

        return 'none';
    }
}
