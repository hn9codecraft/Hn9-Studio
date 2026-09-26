<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Story\Models\StoryPlan;
use App\Story\Models\StoryPlanVersion;
use Illuminate\Support\Collection;

interface StoryPlanVersionRepositoryInterface
{
    /**
     * @return Collection<int, StoryPlanVersion>
     */
    public function listForPlan(StoryPlan $plan): Collection;

    public function findByUuidForPlan(StoryPlan $plan, string $uuid): ?StoryPlanVersion;

    public function nextVersion(StoryPlan $plan): int;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(StoryPlan $plan, array $attributes): StoryPlanVersion;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(StoryPlanVersion $version, array $attributes): StoryPlanVersion;
}
