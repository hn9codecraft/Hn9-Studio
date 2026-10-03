<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionPlanScene;
use Illuminate\Support\Collection;

/**
 * Production plans for a project. Every lookup is scoped to the project's workspace,
 * so an identifier from another project resolves to "not found".
 */
interface StoryProductionPlanServiceInterface
{
    /**
     * @return Collection<int, StoryProductionPlan>
     */
    public function listForProject(Project $project): Collection;

    public function getForProject(Project $project, string $planUuid): StoryProductionPlan;

    public function currentForStoryPlan(Project $project, string $storyPlanUuid): ?StoryProductionPlan;

    public function sceneForPlan(Project $project, string $planUuid, string $sceneUuid): StoryProductionPlanScene;

    /**
     * Plans an approved Story Plan Version whose scenes exist. Repeating the call for the
     * same version returns the existing plan; a different version needs revise().
     * Approval itself goes through StoryPlanApprovalServiceInterface.
     *
     * @return array{plan: StoryProductionPlan, created: bool}
     */
    public function createForVersion(Project $project, string $storyPlanUuid, string $versionUuid, ?User $actor = null): array;

    /**
     * Replaces the current plan with a new revision built from the given version of the
     * same story plan (default: the plan's own version). The old plan stays readable.
     *
     * @return array{plan: StoryProductionPlan, created: bool}
     */
    public function revise(Project $project, string $planUuid, ?string $versionUuid = null, ?User $actor = null): array;
}
