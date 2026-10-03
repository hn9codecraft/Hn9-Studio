<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Story\Enums\StoryPlanStatus;
use App\Story\Enums\StoryPlanVersionStatus;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryPlanVersion;
use App\Story\Models\StoryWorkspace;

/**
 * A project whose story planner has finished a version that is waiting for review.
 */
trait BuildsStoryApprovalFixtures
{
    /**
     * @param  list<int>  $durations
     * @return array{user: User, project: Project, workspace: StoryWorkspace, plan: StoryPlan, version: StoryPlanVersion}
     */
    protected function approvalFixture(array $durations = [30, 17], ?User $owner = null): array
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
        $version = $this->reviewableVersion($plan, 1, $durations);

        return compact('user', 'project', 'workspace', 'plan', 'version');
    }

    /**
     * A finished version that becomes the story plan's latest version.
     *
     * @param  list<int>  $durations
     * @param  array<int, array<string, mixed>>  $overrides  scene fields by index
     */
    protected function reviewableVersion(StoryPlan $plan, int $number, array $durations, array $overrides = []): StoryPlanVersion
    {
        $scenes = [];
        $cursor = 0;
        foreach ($durations as $index => $seconds) {
            $sequence = $index + 1;
            $scenes[] = array_merge([
                'sequence' => $sequence,
                'start_second' => $cursor,
                'end_second' => $cursor + $seconds,
                'duration_seconds' => $seconds,
                'title' => "Scene {$sequence}",
                'story' => "Story beat {$sequence}",
                'characters' => ['Mira'],
                'location' => 'Open sea',
                'dialogue' => [],
                'narration' => null,
                'visual_prompt' => "Visual {$sequence}",
                'motion_prompt' => "Motion {$sequence}",
                'audio_direction' => 'Waves',
                'continuity' => [
                    'previous_scene' => null,
                    'next_scene' => null,
                    'character_state' => 'Hopeful',
                    'environment_state' => 'Calm sea',
                ],
            ], $overrides[$index] ?? []);
            $cursor += $seconds;
        }

        $version = StoryPlanVersion::factory()->create([
            'story_plan_id' => $plan->id,
            'version' => $number,
            'status' => StoryPlanVersionStatus::Completed->value,
            'master_story' => 'Mira follows the whale song.',
            'plan' => [
                'title' => "The Lost Whale v{$number}",
                'logline' => 'Mira follows the whale song.',
                'total_duration_seconds' => $cursor,
                'scene_duration_target_seconds' => 30,
                'scenes' => $scenes,
            ],
        ]);
        $plan->forceFill(['current_version_id' => $version->id])->save();

        return $version;
    }
}
