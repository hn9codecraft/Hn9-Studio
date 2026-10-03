<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Story\Enums\StoryPlanStatus;
use App\Story\Enums\StoryPlanVersionStatus;
use App\Story\Enums\StoryReelStatus;
use App\Story\Enums\StorySceneStatus;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryPlanVersion;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryWorkspace;

/**
 * A project whose finished Story Plan Version has been turned into scenes with the given lengths.
 */
trait BuildsProductionPlanFixtures
{
    /**
     * @param  list<int>  $durations
     * @return array{user: User, project: Project, workspace: StoryWorkspace, plan: StoryPlan, version: StoryPlanVersion, reel: StoryReel, scenes: list<StoryScene>}
     */
    protected function productionFixture(array $durations, ?User $owner = null): array
    {
        $user = $owner ?? User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $workspace = app(StoryWorkspaceServiceInterface::class)->workspaceForProject($project);
        $plan = StoryPlan::factory()->create([
            'story_workspace_id' => $workspace->id,
            'title' => 'The Lost Whale',
            'requested_duration_seconds' => max(30, array_sum($durations)),
            'status' => StoryPlanStatus::Completed->value,
        ]);

        [$version, $reel, $scenes] = $this->materializedVersion($workspace, $plan, 1, $durations);

        return compact('user', 'project', 'workspace', 'plan', 'version', 'reel', 'scenes');
    }

    /**
     * @param  list<int>  $durations
     * @return array{0: StoryPlanVersion, 1: StoryReel, 2: list<StoryScene>}
     */
    protected function materializedVersion(StoryWorkspace $workspace, StoryPlan $plan, int $number, array $durations): array
    {
        $version = StoryPlanVersion::factory()->create([
            'story_plan_id' => $plan->id,
            'version' => $number,
            'status' => StoryPlanVersionStatus::Completed->value,
        ]);
        $plan->forceFill(['current_version_id' => $version->id])->save();

        $reel = StoryReel::factory()->create([
            'story_workspace_id' => $workspace->id,
            'title' => "Reel from version {$number}",
            'status' => StoryReelStatus::Active->value,
            'source_plan_id' => $plan->id,
            'source_plan_version_id' => $version->id,
            'total_duration_seconds' => array_sum($durations),
        ]);

        $scenes = [];
        $cursor = 0;
        foreach ($durations as $index => $seconds) {
            $scenes[] = StoryScene::factory()->create([
                'story_reel_id' => $reel->id,
                'sequence' => $index + 1,
                'title' => 'Scene '.($index + 1),
                'duration_seconds' => $seconds,
                'start_second' => $cursor,
                'end_second' => $cursor + $seconds,
                'status' => StorySceneStatus::Active->value,
                'source_plan_version_id' => $version->id,
            ]);
            $cursor += $seconds;
        }

        return [$version, $reel, $scenes];
    }
}
