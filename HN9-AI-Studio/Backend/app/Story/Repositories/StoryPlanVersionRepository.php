<?php

declare(strict_types=1);

namespace App\Story\Repositories;

use App\Story\Contracts\StoryPlanVersionRepositoryInterface;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryPlanVersion;
use Illuminate\Support\Collection;

final class StoryPlanVersionRepository implements StoryPlanVersionRepositoryInterface
{
    public function listForPlan(StoryPlan $plan): Collection
    {
        return StoryPlanVersion::query()
            ->where('story_plan_id', $plan->id)
            ->orderByDesc('version')
            ->get();
    }

    public function findByUuidForPlan(StoryPlan $plan, string $uuid): ?StoryPlanVersion
    {
        return StoryPlanVersion::query()
            ->where('story_plan_id', $plan->id)
            ->where('uuid', $uuid)
            ->first();
    }

    public function nextVersion(StoryPlan $plan): int
    {
        return ((int) StoryPlanVersion::query()->where('story_plan_id', $plan->id)->max('version')) + 1;
    }

    public function create(StoryPlan $plan, array $attributes): StoryPlanVersion
    {
        return StoryPlanVersion::query()->create([
            ...$attributes,
            'story_plan_id' => $plan->id,
        ]);
    }

    public function update(StoryPlanVersion $version, array $attributes): StoryPlanVersion
    {
        $version->fill($attributes);
        $version->save();

        return $version->refresh();
    }
}
