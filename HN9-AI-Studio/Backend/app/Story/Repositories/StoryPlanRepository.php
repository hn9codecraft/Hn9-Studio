<?php

declare(strict_types=1);

namespace App\Story\Repositories;

use App\Story\Contracts\StoryPlanRepositoryInterface;
use App\Story\Enums\StoryPlanStatus;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryWorkspace;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

final class StoryPlanRepository implements StoryPlanRepositoryInterface
{
    public function listForWorkspace(StoryWorkspace $workspace): Collection
    {
        return StoryPlan::query()
            ->where('story_workspace_id', $workspace->id)
            ->where('status', '!=', StoryPlanStatus::Archived->value)
            ->with(self::relations())
            ->orderByDesc('id')
            ->get();
    }

    public function findByUuidForWorkspace(StoryWorkspace $workspace, string $uuid): ?StoryPlan
    {
        return StoryPlan::query()
            ->where('story_workspace_id', $workspace->id)
            ->where('uuid', $uuid)
            ->with(self::relations())
            ->first();
    }

    public function create(StoryWorkspace $workspace, array $attributes): StoryPlan
    {
        return StoryPlan::query()->create([
            ...$attributes,
            'story_workspace_id' => $workspace->id,
            'status' => $attributes['status'] ?? StoryPlanStatus::Draft->value,
            'duration_unit' => $attributes['duration_unit'] ?? 'seconds',
        ])->loadMissing(self::relations());
    }

    public function update(StoryPlan $plan, array $attributes): StoryPlan
    {
        $plan->fill($attributes);
        $plan->save();

        // refresh() would reload relations without their constraints, so they are loaded afresh.
        return $plan->refresh()->unsetRelations()->load(self::relations());
    }

    /**
     * @return array<int|string, mixed>
     */
    public static function relations(): array
    {
        return [
            'currentVersion',
            'workspace.project',
            'currentProductionPlan' => static fn (HasOne $query) => $query
                ->with(['sourceVersion', 'reel'])
                ->withCount(['scenes', 'units']),
        ];
    }
}
