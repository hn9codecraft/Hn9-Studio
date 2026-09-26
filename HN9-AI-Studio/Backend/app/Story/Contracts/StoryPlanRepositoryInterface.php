<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Story\Models\StoryPlan;
use App\Story\Models\StoryWorkspace;
use Illuminate\Support\Collection;

interface StoryPlanRepositoryInterface
{
    /**
     * @return Collection<int, StoryPlan>
     */
    public function listForWorkspace(StoryWorkspace $workspace): Collection;

    public function findByUuidForWorkspace(StoryWorkspace $workspace, string $uuid): ?StoryPlan;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(StoryWorkspace $workspace, array $attributes): StoryPlan;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(StoryPlan $plan, array $attributes): StoryPlan;
}
