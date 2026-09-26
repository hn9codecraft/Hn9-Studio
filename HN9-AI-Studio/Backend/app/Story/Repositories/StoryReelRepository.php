<?php

declare(strict_types=1);

namespace App\Story\Repositories;

use App\Story\Contracts\StoryReelRepositoryInterface;
use App\Story\Enums\StoryReelStatus;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryWorkspace;
use Illuminate\Support\Collection;

final class StoryReelRepository implements StoryReelRepositoryInterface
{
    public function listForWorkspace(StoryWorkspace $workspace, bool $includeArchived = false): Collection
    {
        $query = StoryReel::query()
            ->where('story_workspace_id', $workspace->id)
            ->with(['scenes', 'sourcePlan', 'sourcePlanVersion', 'workspace.project'])
            ->orderBy('sequence')
            ->orderBy('id');

        if (! $includeArchived) {
            $query->where('status', '!=', StoryReelStatus::Archived->value);
        }

        return $query->get();
    }

    public function findByUuidForWorkspace(StoryWorkspace $workspace, string $uuid): ?StoryReel
    {
        return StoryReel::query()
            ->where('story_workspace_id', $workspace->id)
            ->where('uuid', $uuid)
            ->with(['scenes', 'sourcePlan', 'sourcePlanVersion', 'workspace.project'])
            ->first();
    }

    public function findBySourcePlanVersionId(StoryWorkspace $workspace, int $planVersionId): ?StoryReel
    {
        return StoryReel::query()
            ->where('story_workspace_id', $workspace->id)
            ->where('source_plan_version_id', $planVersionId)
            ->with(['scenes', 'sourcePlan', 'sourcePlanVersion', 'workspace.project'])
            ->first();
    }

    public function create(StoryWorkspace $workspace, array $attributes): StoryReel
    {
        return StoryReel::query()->create([
            ...$attributes,
            'story_workspace_id' => $workspace->id,
            'status' => $attributes['status'] ?? StoryReelStatus::Draft->value,
            'total_duration_seconds' => $attributes['total_duration_seconds'] ?? 0,
        ])->loadMissing(['scenes', 'sourcePlan', 'sourcePlanVersion', 'workspace.project']);
    }

    public function update(StoryReel $reel, array $attributes): StoryReel
    {
        $reel->fill($attributes);
        $reel->save();

        return $reel->refresh()->loadMissing(['scenes', 'sourcePlan', 'sourcePlanVersion', 'workspace.project']);
    }

    public function nextSequence(StoryWorkspace $workspace): int
    {
        $max = (int) StoryReel::query()
            ->where('story_workspace_id', $workspace->id)
            ->where('status', '!=', StoryReelStatus::Archived->value)
            ->max('sequence');

        return $max + 1;
    }
}
