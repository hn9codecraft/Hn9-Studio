<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Enums\StoryPlanStatus;
use App\Story\Enums\StoryPlanVersionStatus;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryPlanVersion;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StoryReelSceneApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_reel_and_scene_requests_are_rejected(): void
    {
        $uuid = (string) Str::uuid();
        $reel = (string) Str::uuid();
        $scene = (string) Str::uuid();
        $plan = (string) Str::uuid();
        $version = (string) Str::uuid();

        $this->getJson("/api/v1/story/projects/{$uuid}/reels")->assertUnauthorized();
        $this->postJson("/api/v1/story/projects/{$uuid}/reels", [])->assertUnauthorized();
        $this->getJson("/api/v1/story/projects/{$uuid}/reels/{$reel}")->assertUnauthorized();
        $this->patchJson("/api/v1/story/projects/{$uuid}/reels/{$reel}", [])->assertUnauthorized();
        $this->postJson("/api/v1/story/projects/{$uuid}/reels/{$reel}/archive")->assertUnauthorized();
        $this->postJson("/api/v1/story/projects/{$uuid}/reels/reorder", [])->assertUnauthorized();
        $this->getJson("/api/v1/story/projects/{$uuid}/reels/{$reel}/scenes")->assertUnauthorized();
        $this->postJson("/api/v1/story/projects/{$uuid}/reels/{$reel}/scenes", [])->assertUnauthorized();
        $this->getJson("/api/v1/story/projects/{$uuid}/reels/{$reel}/scenes/{$scene}")->assertUnauthorized();
        $this->patchJson("/api/v1/story/projects/{$uuid}/reels/{$reel}/scenes/{$scene}", [])->assertUnauthorized();
        $this->postJson("/api/v1/story/projects/{$uuid}/reels/{$reel}/scenes/{$scene}/duplicate")->assertUnauthorized();
        $this->postJson("/api/v1/story/projects/{$uuid}/reels/{$reel}/scenes/{$scene}/archive")->assertUnauthorized();
        $this->postJson("/api/v1/story/projects/{$uuid}/reels/{$reel}/scenes/reorder", [])->assertUnauthorized();
        $this->postJson("/api/v1/story/projects/{$uuid}/plans/{$plan}/versions/{$version}/materialize")->assertUnauthorized();
    }

    public function test_owner_can_create_read_update_and_archive_reel(): void
    {
        [$user, $project] = $this->ownerProject();

        $created = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels", [
                'title' => 'Dragon Discovery',
                'description' => 'Day 1 reel',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Dragon Discovery')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_duration_seconds', 0);

        $reelId = $created->json('data.id');
        $this->assertTrue(Str::isUuid($reelId));

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/reels/{$reelId}")
            ->assertOk()
            ->assertJsonPath('data.description', 'Day 1 reel');

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$project->uuid}/reels/{$reelId}", [
                'title' => 'Dragon Discovery Updated',
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Dragon Discovery Updated')
            ->assertJsonPath('data.status', 'active');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$reelId}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/reels")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_scene_create_reorder_duplicate_and_timing(): void
    {
        [$user, $project] = $this->ownerProject();
        $reelId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels", ['title' => 'Reel 1'])
            ->json('data.id');

        $s1 = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$reelId}/scenes", [
                'title' => 'Scene A',
                'duration_seconds' => 30,
                'story' => 'A enters',
                'characters' => ['Aarav'],
                'location' => 'Cave',
                'visual_prompt' => 'Wide cave',
                'motion_prompt' => 'Push in',
                'continuity' => [
                    'previous_scene' => null,
                    'next_scene' => 'Deeper cave',
                    'character_state' => 'Cautious',
                    'environment_state' => 'Dark mouth',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.sequence', 1)
            ->assertJsonPath('data.start_second', 0)
            ->assertJsonPath('data.end_second', 30);

        $s2 = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$reelId}/scenes", [
                'title' => 'Scene B',
                'duration_seconds' => 30,
                'story' => 'B continues',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sequence', 2)
            ->assertJsonPath('data.start_second', 30)
            ->assertJsonPath('data.end_second', 60);

        $s3 = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$reelId}/scenes", [
                'title' => 'Scene C',
                'duration_seconds' => 15,
                'story' => 'Short end',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sequence', 3)
            ->assertJsonPath('data.start_second', 60)
            ->assertJsonPath('data.end_second', 75);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/reels/{$reelId}")
            ->assertOk()
            ->assertJsonPath('data.total_duration_seconds', 75)
            ->assertJsonPath('data.scene_count', 3);

        $id1 = $s1->json('data.id');
        $id2 = $s2->json('data.id');
        $id3 = $s3->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$reelId}/scenes/reorder", [
                'ordered_ids' => [$id3, $id1, $id2],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $id3)
            ->assertJsonPath('data.0.start_second', 0)
            ->assertJsonPath('data.0.end_second', 15)
            ->assertJsonPath('data.1.id', $id1)
            ->assertJsonPath('data.1.start_second', 15)
            ->assertJsonPath('data.1.end_second', 45)
            ->assertJsonPath('data.2.id', $id2)
            ->assertJsonPath('data.2.start_second', 45)
            ->assertJsonPath('data.2.end_second', 75);

        $dup = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$reelId}/scenes/{$id1}/duplicate")
            ->assertCreated();

        $this->assertNotSame($id1, $dup->json('data.id'));
        $this->assertStringContainsString('(copy)', (string) $dup->json('data.title'));

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$project->uuid}/reels/{$reelId}/scenes/{$id3}", [
                'duration_seconds' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('data.duration_seconds', 20);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/reels/{$reelId}/scenes/{$id1}")
            ->assertOk()
            ->assertJsonPath('data.continuity.character_state', 'Cautious');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$reelId}/scenes/{$id2}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/reels/{$reelId}/scenes")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_reel_reorder_and_long_running_continuation(): void
    {
        [$user, $project] = $this->ownerProject();

        $r1 = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels", ['title' => 'Day 1'])
            ->json('data.id');
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$r1}/scenes", [
                'title' => 'S1',
                'duration_seconds' => 30,
                'story' => 'Day1 scene1',
            ]);
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$r1}/scenes", [
                'title' => 'S2',
                'duration_seconds' => 30,
                'story' => 'Day1 scene2',
            ]);

        $r2 = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels", ['title' => 'Day 2'])
            ->json('data.id');
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$r2}/scenes", [
                'title' => 'S3',
                'duration_seconds' => 30,
                'story' => 'Day2 scene1',
            ]);
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$r2}/scenes", [
                'title' => 'S4',
                'duration_seconds' => 30,
                'story' => 'Day2 scene2',
            ]);
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$r2}/scenes", [
                'title' => 'S5',
                'duration_seconds' => 30,
                'story' => 'Day2 scene3',
            ]);

        $list = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/reels")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame(60, $list->json('data.0.total_duration_seconds'));
        $this->assertSame(2, $list->json('data.0.scene_count'));
        $this->assertSame(90, $list->json('data.1.total_duration_seconds'));
        $this->assertSame(3, $list->json('data.1.scene_count'));

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/reorder", [
                'ordered_ids' => [$r2, $r1],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $r2)
            ->assertJsonPath('data.1.id', $r1);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/reels/{$r1}")
            ->assertOk()
            ->assertJsonPath('data.scene_count', 2)
            ->assertJsonPath('data.total_duration_seconds', 60);
    }

    public function test_materialize_plan_version_snapshots_scenes_idempotently(): void
    {
        [$user, $project, $workspace] = $this->ownerProjectWithWorkspace();
        [$plan, $version] = $this->seedCompletedPlan($workspace, 60);

        $first = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans/{$plan->uuid}/versions/{$version->uuid}/materialize")
            ->assertCreated()
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.scene_count', 2)
            ->assertJsonPath('data.reel.scene_count', 2)
            ->assertJsonPath('data.reel.total_duration_seconds', 60)
            ->assertJsonPath('data.reel.scenes.0.start_second', 0)
            ->assertJsonPath('data.reel.scenes.0.end_second', 30)
            ->assertJsonPath('data.reel.scenes.1.start_second', 30)
            ->assertJsonPath('data.reel.scenes.1.end_second', 60)
            ->assertJsonPath('data.reel.scenes.0.characters.0', 'Aarav')
            ->assertJsonPath('data.reel.scenes.0.continuity.character_state', 'Cautious')
            ->assertJsonPath('data.reel.source_plan_version.id', $version->uuid);

        $reelId = $first->json('data.reel.id');

        $second = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans/{$plan->uuid}/versions/{$version->uuid}/materialize")
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.reel.id', $reelId);

        $this->assertSame(1, StoryReel::query()->where('story_workspace_id', $workspace->id)->count());
        $this->assertSame(2, StoryScene::query()->count());
    }

    public function test_materialize_rejects_malformed_plan_and_rolls_back(): void
    {
        [$user, $project, $workspace] = $this->ownerProjectWithWorkspace();
        $plan = StoryPlan::factory()->create([
            'story_workspace_id' => $workspace->id,
            'status' => StoryPlanStatus::Completed->value,
        ]);
        $version = StoryPlanVersion::factory()->create([
            'story_plan_id' => $plan->id,
            'status' => StoryPlanVersionStatus::Completed->value,
            'plan' => [
                'title' => 'Broken',
                'logline' => 'x',
                'total_duration_seconds' => 30,
                'scene_duration_target_seconds' => 30,
                'scenes' => [
                    [
                        'sequence' => 1,
                        'duration_seconds' => 30,
                        // missing story / visual / motion
                    ],
                ],
            ],
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans/{$plan->uuid}/versions/{$version->uuid}/materialize")
            ->assertStatus(422);

        $this->assertSame(0, StoryReel::query()->count());
        $this->assertSame(0, StoryScene::query()->count());
    }

    public function test_non_owner_cannot_access_reels_scenes_or_materialize(): void
    {
        [$owner, $project, $workspace] = $this->ownerProjectWithWorkspace();
        $intruder = User::factory()->create();
        [$plan, $version] = $this->seedCompletedPlan($workspace, 30);

        $reelId = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels", ['title' => 'Private'])
            ->json('data.id');
        $sceneId = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$reelId}/scenes", [
                'title' => 'S',
                'story' => 'Secret',
            ])
            ->json('data.id');

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/reels")
            ->assertForbidden();
        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels", ['title' => 'Hack'])
            ->assertForbidden();
        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/reels/{$reelId}/scenes/{$sceneId}")
            ->assertForbidden();
        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans/{$plan->uuid}/versions/{$version->uuid}/materialize")
            ->assertForbidden();
    }

    public function test_invalid_uuids_return_safe_404(): void
    {
        [$user, $project] = $this->ownerProject();
        $missing = (string) Str::uuid();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/reels/{$missing}")
            ->assertNotFound();
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$missing}/reels")
            ->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Project}
     */
    private function ownerProject(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        return [$user, $project];
    }

    /**
     * @return array{0: User, 1: Project, 2: StoryWorkspace}
     */
    private function ownerProjectWithWorkspace(): array
    {
        [$user, $project] = $this->ownerProject();
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}")
            ->assertOk();

        $workspace = StoryWorkspace::query()->where('project_id', $project->id)->firstOrFail();

        return [$user, $project, $workspace];
    }

    /**
     * @return array{0: StoryPlan, 1: StoryPlanVersion}
     */
    private function seedCompletedPlan(StoryWorkspace $workspace, int $totalSeconds): array
    {
        $plan = StoryPlan::factory()->create([
            'story_workspace_id' => $workspace->id,
            'title' => 'Cave Continuation',
            'requested_duration_seconds' => $totalSeconds,
            'status' => StoryPlanStatus::Completed->value,
        ]);

        $scenes = [];
        $count = intdiv($totalSeconds, 30);
        $remainder = $totalSeconds % 30;
        $cursor = 0;
        $seq = 1;
        for ($i = 0; $i < $count; $i++) {
            $scenes[] = $this->scenePayload($seq++, $cursor, 30);
            $cursor += 30;
        }
        if ($remainder > 0) {
            $scenes[] = $this->scenePayload($seq, $cursor, $remainder);
        }

        $version = StoryPlanVersion::factory()->create([
            'story_plan_id' => $plan->id,
            'version' => 1,
            'status' => StoryPlanVersionStatus::Completed->value,
            'master_story' => 'Aarav continues into the cave.',
            'plan' => [
                'title' => 'Cave Continuation',
                'logline' => 'Aarav steps deeper.',
                'total_duration_seconds' => $totalSeconds,
                'scene_duration_target_seconds' => 30,
                'scenes' => $scenes,
            ],
            'remainder_strategy' => $remainder === 0 ? 'exact' : 'final_short_scene',
        ]);

        $plan->update(['current_version_id' => $version->id]);

        return [$plan, $version];
    }

    /**
     * @return array<string, mixed>
     */
    private function scenePayload(int $sequence, int $start, int $duration): array
    {
        return [
            'sequence' => $sequence,
            'start_second' => $start,
            'end_second' => $start + $duration,
            'duration_seconds' => $duration,
            'title' => "Scene {$sequence}",
            'story' => "Story beat {$sequence}",
            'characters' => ['Aarav'],
            'location' => 'Mountain cave',
            'dialogue' => [],
            'narration' => null,
            'visual_prompt' => "Visual {$sequence}",
            'motion_prompt' => "Motion {$sequence}",
            'audio_direction' => 'Ambient cave',
            'continuity' => [
                'previous_scene' => $sequence === 1 ? null : 'Prior beat',
                'next_scene' => 'Next beat',
                'character_state' => 'Cautious',
                'environment_state' => 'Dark cave',
            ],
        ];
    }
}
