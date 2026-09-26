<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Models\Project;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryPlanVersion;
use Illuminate\Support\Collection;

interface StoryPlannerServiceInterface
{
    /**
     * @return Collection<int, StoryPlan>
     */
    public function listForProject(Project $project): Collection;

    public function getForProject(Project $project, string $planUuid): StoryPlan;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Project $project, array $attributes): StoryPlan;

    /**
     * @param  array<string, mixed>  $options
     */
    public function generate(Project $project, string $planUuid, array $options = []): StoryPlan;

    /**
     * @param  array<string, mixed>  $options
     */
    public function regenerate(Project $project, string $planUuid, ?string $instruction = null, array $options = []): StoryPlan;

    /**
     * @return Collection<int, StoryPlanVersion>
     */
    public function versionsForProject(Project $project, string $planUuid): Collection;
}
