<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Enums\StoryReviewStatus;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StoryProductionPlanScene;
use App\Story\Models\StoryProductionUnitVersion;
use App\Story\Models\StorySceneAssembly;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryTimeline;
use App\Story\Models\StoryTimelineClip;
use App\Story\Models\StoryVersionRestore;
use App\Story\Models\StoryVideoGenerationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\TestCase;

final class StoryVersionRestoreTest extends TestCase
{
    use BuildsProductionPlanFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('videos');
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_restoring_an_older_assembly_repoints_the_timeline_and_keeps_history(): void
    {
        [$user, $project, $plan, $scene, $older, $newer, $clip] = $this->pair(10, 10, 1000, 8000);

        $this->actingAs($user, 'sanctum')
            ->postJson($this->restoreUrl($project, $plan, $scene, $older))
            ->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.restored.uuid', $older->uuid)
            ->assertJsonPath('data.previous.uuid', $newer->uuid)
            ->assertJsonPath('data.timeline_clips_updated', 1)
            ->assertJsonPath('data.trim_adjusted', false);

        $clip->refresh();
        $older->refresh();
        $newer->refresh();
        $this->assertSame($older->path, $clip->path);
        $this->assertSame($older->id, $clip->story_scene_assembly_id);
        $this->assertNotNull($older->selected_at);
        $this->assertNull($newer->selected_at);
        $this->assertSame(1000, $clip->in_ms);
        $this->assertSame(8000, $clip->out_ms);
        $this->assertSame(1, StoryVersionRestore::query()->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'story.version_restored')->count());
        Http::assertNothingSent();
    }

    public function test_restoring_the_newer_assembly_puts_the_original_source_back(): void
    {
        [$user, $project, $plan, $scene, $older, $newer, $clip] = $this->pair(10, 10, 1000, 8000);
        $original = $newer->path;

        $this->actingAs($user, 'sanctum')->postJson($this->restoreUrl($project, $plan, $scene, $older))->assertOk();
        $this->actingAs($user, 'sanctum')
            ->postJson($this->restoreUrl($project, $plan, $scene, $newer))
            ->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.trim_adjusted', false);

        $clip->refresh();
        $this->assertSame($original, $clip->path);
        $this->assertSame($newer->id, $clip->story_scene_assembly_id);
        $this->assertSame(1000, $clip->in_ms);
        $this->assertSame(8000, $clip->out_ms);
        Http::assertNothingSent();
    }

    public function test_trim_is_clamped_when_the_restored_version_is_shorter_and_kept_when_it_fits(): void
    {
        [$user, $project, $plan, $scene, $older, , $clip] = $this->pair(3, 10, 1000, 8000);

        $this->actingAs($user, 'sanctum')
            ->postJson($this->restoreUrl($project, $plan, $scene, $older))
            ->assertOk()
            ->assertJsonPath('data.trim_adjusted', true);

        $clip->refresh();
        $this->assertSame(1000, $clip->in_ms);
        $this->assertSame(3000, $clip->out_ms);

        [$user, $project, $plan, $scene, $older, , $clip] = $this->pair(10, 10, 500, 4000);
        $this->actingAs($user, 'sanctum')
            ->postJson($this->restoreUrl($project, $plan, $scene, $older))
            ->assertOk()
            ->assertJsonPath('data.trim_adjusted', false);
        $clip->refresh();
        $this->assertSame(500, $clip->in_ms);
        $this->assertSame(4000, $clip->out_ms);
    }

    public function test_restoring_the_current_assembly_changes_nothing(): void
    {
        [$user, $project, $plan, $scene, , $newer] = $this->pair(10, 10, 0, 10000);

        $this->actingAs($user, 'sanctum')
            ->postJson($this->restoreUrl($project, $plan, $scene, $newer))
            ->assertOk()
            ->assertJsonPath('data.changed', false);

        $this->assertSame(0, StoryVersionRestore::query()->count());
    }

    public function test_a_version_that_is_not_ready_or_not_owned_cannot_be_restored(): void
    {
        [$user, $project, $plan, $scene, $older] = $this->pair(10, 10, 0, 10000);
        $older->forceFill(['status' => 'failed'])->save();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->restoreUrl($project, $plan, $scene, $older))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_version_not_restorable');

        $older->forceFill(['status' => 'completed'])->save();
        Storage::disk('videos')->delete($older->path);
        $this->actingAs($user, 'sanctum')
            ->postJson($this->restoreUrl($project, $plan, $scene, $older))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_version_not_restorable');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/story/projects/'.$project->uuid.'/production-plans/'.$plan->uuid.'/scenes/'.$scene->scene->uuid.'/assemblies/00000000-0000-4000-8000-000000000000/restore')
            ->assertNotFound();

        $foreign = $this->productionFixture([10]);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/story/projects/'.$project->uuid.'/production-plans/'.$foreign['plan']->uuid.'/scenes/'.$scene->scene->uuid.'/assemblies/'.$older->uuid.'/restore')
            ->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->postJson($this->restoreUrl($project, $plan, $scene, $older))->assertUnauthorized();

        $member = User::factory()->create();
        $this->actingAs($member, 'sanctum')
            ->postJson($this->restoreUrl($project, $plan, $scene, $older))
            ->assertForbidden();

        $token = $user->createToken('api')->plainTextToken;
        $user->forceFill(['status' => 'inactive'])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson($this->restoreUrl($project, $plan, $scene, $older))->assertUnauthorized();
        $this->assertSame(0, StoryVersionRestore::query()->count());
    }

    public function test_a_render_in_progress_blocks_restore_without_changing_anything(): void
    {
        [$user, $project, $plan, $scene, $older, $newer, $clip] = $this->pair(10, 10, 0, 10000);
        StoryFinalRender::query()->create([
            'story_timeline_id' => $clip->story_timeline_id,
            'story_reel_id' => $scene->scene->reel->id,
            'status' => 'rendering',
            'timeline_version' => hash('sha256', 'busy'),
            'timeline_snapshot' => ['clips' => []],
        ]);
        $before = $clip->path;

        $this->actingAs($user, 'sanctum')
            ->postJson($this->restoreUrl($project, $plan, $scene, $older))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_render_in_progress');

        $clip->refresh();
        $this->assertSame($before, $clip->path);
        $this->assertNull($older->fresh()->selected_at);
        $this->assertSame(0, StoryVersionRestore::query()->count());
        $this->assertNotNull($newer->fresh());
    }

    public function test_restore_deletes_nothing_and_marks_an_existing_render_stale(): void
    {
        [$user, $project, $plan, $scene, $older, , $clip] = $this->pair(10, 10, 0, 10000);
        Storage::disk('videos')->put('renders/kept.mp4', 'kept-output');
        StoryFinalRender::query()->create([
            'story_timeline_id' => $clip->story_timeline_id,
            'story_reel_id' => $scene->scene->reel->id,
            'status' => 'completed',
            'timeline_version' => hash('sha256', 'done'),
            'timeline_snapshot' => ['clips' => [['path' => 'renders/kept.mp4']]],
            'disk' => 'videos',
            'path' => 'renders/kept.mp4',
        ]);
        $assemblies = StorySceneAssembly::query()->count();
        $renders = StoryFinalRender::query()->count();
        $files = count(Storage::disk('videos')->allFiles());

        $this->actingAs($user, 'sanctum')
            ->postJson($this->restoreUrl($project, $plan, $scene, $older))
            ->assertOk()
            ->assertJsonPath('data.render_stale', true);

        $this->assertSame($assemblies, StorySceneAssembly::query()->count());
        $this->assertSame($renders, StoryFinalRender::query()->count());
        $this->assertSame($files, count(Storage::disk('videos')->allFiles()));
        $this->assertSame('kept-output', Storage::disk('videos')->get('renders/kept.mp4'));
    }

    public function test_the_versions_list_has_units_assemblies_and_history_without_disk_paths(): void
    {
        [$user, $project, $plan, $scene, $older] = $this->pair(10, 10, 0, 10000);
        $unit = $scene->units()->firstOrFail();
        Storage::disk('videos')->put('clips/unit-secret.mp4', 'unit-bytes');
        $version = new StoryProductionUnitVersion;
        $version->forceFill([
            'story_production_unit_id' => $unit->id,
            'version_number' => 1,
            'review_status' => StoryReviewStatus::Approved->value,
            'disk' => 'videos',
            'path' => 'clips/unit-secret.mp4',
            'mime' => 'video/mp4',
            'size' => 10,
            'requested_duration_seconds' => 10,
            'produced_duration_seconds' => 10,
        ])->save();
        $unit->forceFill(['selected_version_id' => $version->id])->save();
        $this->actingAs($user, 'sanctum')->postJson($this->restoreUrl($project, $plan, $scene, $older))->assertOk();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/story/projects/'.$project->uuid.'/production-plans/'.$plan->uuid.'/scenes/'.$scene->scene->uuid.'/versions')
            ->assertOk();

        $this->assertNotEmpty($response->json('data.units.0.versions'));
        $this->assertTrue($response->json('data.units.0.versions.0.selected'));
        $this->assertCount(2, $response->json('data.assemblies'));
        $this->assertNotEmpty($response->json('data.history'));
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('clips/unit-secret.mp4', $body);
        $this->assertStringNotContainsString('assemblies/', $body);
        $this->assertStringNotContainsString('"disk"', $body);
        $this->assertStringContainsString('/file', $body);
    }

    public function test_restoring_an_older_scene_version_repoints_playback_and_leaves_review_latest_alone(): void
    {
        $fixture = $this->productionFixture([10]);
        $scene = $fixture['scenes'][0];
        $older = $this->sceneVersion($fixture['workspace']->id, $scene, 1, 'story/older-scene.mp4');
        $newer = $this->sceneVersion($fixture['workspace']->id, $scene, 2, 'story/newer-scene.mp4');
        $timeline = StoryTimeline::query()->create(['story_reel_id' => $scene->reel->id]);
        $clip = StoryTimelineClip::query()->create([
            'story_timeline_id' => $timeline->id,
            'position' => 1,
            'media_kind' => 'video',
            'story_scene_version_id' => $newer->id,
            'disk' => 'videos',
            'path' => 'story/newer-scene.mp4',
            'in_ms' => 0,
            'out_ms' => 8000,
        ]);

        $this->actingAs($fixture['user'], 'sanctum')
            ->postJson('/api/v1/story/projects/'.$fixture['project']->uuid.'/reels/'.$scene->reel->uuid.'/scenes/'.$scene->uuid.'/versions/'.$older->uuid.'/restore')
            ->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.restored.uuid', $older->uuid)
            ->assertJsonPath('data.timeline_clips_updated', 1);

        $clip->refresh();
        $this->assertSame('story/older-scene.mp4', $clip->path);
        $this->assertSame($older->id, $clip->story_scene_version_id);
        $this->assertSame(2, (int) StorySceneVersion::query()->where('story_scene_id', $scene->id)->max('version'));
        $this->assertSame(1, StoryVersionRestore::query()->where('subject_type', 'scene_version')->count());
        Http::assertNothingSent();
    }

    private function sceneVersion(int $workspaceId, \App\Story\Models\StoryScene $scene, int $number, string $path): StorySceneVersion
    {
        Storage::disk('videos')->put($path, 'scene-'.$number);
        $version = StorySceneVersion::query()->create([
            'story_scene_id' => $scene->id,
            'version' => $number,
            'status' => 'approved',
            'title' => 'Scene version '.$number,
        ]);
        StoryVideoGenerationJob::factory()->create([
            'story_workspace_id' => $workspaceId,
            'story_reel_id' => $scene->story_reel_id,
            'story_scene_id' => $scene->id,
            'status' => 'completed',
            'provider_metadata' => [
                'version_id' => $version->uuid,
                'duration_seconds' => 10,
                'storage' => ['disk' => 'videos', 'path' => $path, 'mime' => 'video/mp4'],
            ],
        ]);

        return $version;
    }

    /**
     * @return array{0: User, 1: Project, 2: mixed, 3: StoryProductionPlanScene, 4: StorySceneAssembly, 5: StorySceneAssembly, 6: StoryTimelineClip}
     */
    private function pair(float $olderSeconds, float $newerSeconds, int $inMs, int $outMs): array
    {
        $fixture = $this->productionFixture([10]);
        $plan = app(StoryProductionPlanServiceInterface::class)->createForVersion(
            $fixture['project'],
            $fixture['plan']->uuid,
            $fixture['version']->uuid,
            $fixture['user'],
        )['plan'];
        $scene = StoryProductionPlanScene::query()
            ->where('story_production_plan_id', $plan->id)
            ->with(['scene.reel', 'units'])
            ->firstOrFail();
        $workspaceId = (int) $fixture['workspace']->id;
        $older = $this->assembly($scene, $workspaceId, 'older-'.$scene->id, $olderSeconds, 1);
        $newer = $this->assembly($scene, $workspaceId, 'newer-'.$scene->id, $newerSeconds, 2);
        $timeline = StoryTimeline::query()->create(['story_reel_id' => $scene->scene->reel->id]);
        $clip = StoryTimelineClip::query()->create([
            'story_timeline_id' => $timeline->id,
            'position' => 1,
            'media_kind' => 'video',
            'story_scene_assembly_id' => $newer->id,
            'disk' => 'videos',
            'path' => $newer->path,
            'in_ms' => $inMs,
            'out_ms' => $outMs,
        ]);

        return [$fixture['user'], $fixture['project'], $plan, $scene, $older, $newer, $clip];
    }

    private function assembly(StoryProductionPlanScene $scene, int $workspaceId, string $name, float $seconds, int $number): StorySceneAssembly
    {
        $path = 'assemblies/'.$name.'.mp4';
        Storage::disk('videos')->put($path, $name);
        $row = new StorySceneAssembly;
        $row->forceFill([
            'story_workspace_id' => $workspaceId,
            'story_production_plan_scene_id' => $scene->id,
            'version_number' => $number,
            'idempotency_key' => $name,
            'status' => 'completed',
            'snapshot' => ['units' => []],
            'expected_duration_seconds' => (int) $seconds,
            'disk' => 'videos',
            'path' => $path,
            'mime' => 'video/mp4',
            'size_bytes' => strlen($name),
            'duration_seconds' => $seconds,
            'completed_at' => now(),
        ])->save();

        return $row;
    }

    private function restoreUrl(Project $project, mixed $plan, StoryProductionPlanScene $scene, StorySceneAssembly $assembly): string
    {
        return '/api/v1/story/projects/'.$project->uuid.'/production-plans/'.$plan->uuid.'/scenes/'.$scene->scene->uuid.'/assemblies/'.$assembly->uuid.'/restore';
    }
}
