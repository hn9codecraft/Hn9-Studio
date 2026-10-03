<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryPlanVersion;
use App\Story\Models\StoryProductionPlan;

/**
 * Approval of a Story Plan Version: the boundary between story planning and video production.
 */
interface StoryPlanApprovalServiceInterface
{
    /**
     * Approves a finished Story Plan Version and prepares it for production in one transaction:
     * its scenes are created (or reused), the version is stamped approved, and the story's
     * production plan is created, or revised, from exactly this version. If any step fails,
     * nothing is saved. Repeating the call returns the same outcome without creating anything.
     *
     * @return array{plan: StoryPlan, version: StoryPlanVersion, production_plan: StoryProductionPlan, approved: bool, created: bool}
     */
    public function approve(Project $project, string $storyPlanUuid, string $versionUuid, User $actor): array;
}
