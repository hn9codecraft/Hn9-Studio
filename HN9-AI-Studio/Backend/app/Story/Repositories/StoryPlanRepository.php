<?php

declare(strict_types=1);

namespace App\Story\Repositories;

use App\Story\Contracts\StoryPlanRepositoryInterface;
use App\Story\Enums\StoryPlanStatus;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryWorkspace;
use Illuminate\Support\Collection;

final class StoryPlanRepository implements StoryPlanRepositoryInterface
{
    public function listForWorkspace(StoryWorkspace $workspace): Collection
    {
        return StoryPlan::query()
            ->where('story_workspace_id', $workspace->id)
            ->where('status', '!=', StoryPlanStatus::Archived->value)
            ->with(['currentVersion', 'workspace.project'])
            ->orderByDesc('id')
            ->get();
    }

    public function findByUuidForWorkspace(StoryWorkspace $workspace, string $uuid): ?StoryPlan
    {
        return StoryPlan::query()
            ->where('story_workspace_id', $workspace->id)
            ->where('uuid', $uuid)
            ->with(['currentVersion', 'workspace.project'])
            ->first();
    }

    public function create(StoryWorkspace $workspace, array $attributes): StoryPlan
    {
        return StoryPlan::query()->create([
            ...$attributes,
            'story_workspace_id' => $workspace->id,
            'status' => $attributes['status'] ?? StoryPlanStatus::Draft->value,
            'duration_unit' => $attributes['duration_unit'] ?? 'seconds',
        ])->loadMissing(['currentVersion', 'workspace.project']);
    }

    public function update(StoryPlan $plan, array $attributes): StoryPlan
    {
        $plan->fill($attributes);
        $plan->save();

        return $plan->refresh()->loadMissing(['currentVersion', 'workspace.project']);
    }
}
