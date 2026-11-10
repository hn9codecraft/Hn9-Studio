<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessStoryRenderJob;
use App\Models\Project;
use App\Models\User;
use App\Story\Media\StoryMediaToolkit;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeStoryMediaToolkit;
use Tests\TestCase;

final class StoryRenderQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('videos');
        Http::preventStrayRequests();
        Http::fake();
        config(['story_render.queue.enabled' => false]);
    }

    public function test_the_sync_path_is_unchanged_when_the_queue_flag_is_off(): void
    {
        Queue::fake();
        $this->media();
        [$owner, $project, $reel, $first] = $this->videos();
        $this->place($owner, $project, $reel, $first['version']->uuid);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson($this->renders($project, $reel))
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertArrayNotHasKey('queued', $response->json('data'));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_a_queued_render_returns_202_and_dispatches_one_job(): void
    {
        Queue::fake();
        config(['story_render.queue.enabled' => true]);
        $this->media();
        [$owner, $project, $reel, $first] = $this->videos();
        $this->place($owner, $project, $reel, $first['version']->uuid);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->renders($project, $reel))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.queued', true);

        Queue::assertPushed(ProcessStoryRenderJob::class, function (ProcessStoryRenderJob $job): bool {
            return $job->afterCommit === true && $job->queue === 'default';
        });
        $this->assertSame(1, Queue::pushed(ProcessStoryRenderJob::class)->count());
        Http::assertNothingSent();
    }

    public function test_a_second_render_is_rejected_while_one_is_active(): void
    {
        Queue::fake();
        config(['story_render.queue.enabled' => true]);
        $this->media();
        [$owner, $project, $reel, $first] = $this->videos();
        $this->place($owner, $project, $reel, $first['version']->uuid);

        $this->actingAs($owner, 'sanctum')->postJson($this->renders($project, $reel))->assertStatus(202);
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->renders($project, $reel))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_render_in_progress');

        config(['story_render.queue.enabled' => false]);
        [$owner, $project, $reel, $first] = $this->videos();
        $this->place($owner, $project, $reel, $first['version']->uuid);
        $timelineId = \App\Story\Models\StoryTimeline::query()->where('story_reel_id', $reel->id)->value('id');
        StoryFinalRender::query()->create([
            'story_timeline_id' => $timelineId,
            'story_reel_id' => $reel->id,
            'status' => 'processing',
            'timeline_version' => hash('sha256', 'busy'),
            'timeline_snapshot' => ['clips' => []],
        ]);
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->renders($project, $reel))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_render_in_progress');
        Http::assertNothingSent();
    }

    public function test_the_worker_claims_once_and_notifies_only_the_requester(): void
    {
        $this->media();
        [$owner, $project, $reel, $first] = $this->videos();
        $this->place($owner, $project, $reel, $first['version']->uuid);
        $other = User::factory()->create();
        $render = $this->queuedRender($reel, $owner);

        $job = new ProcessStoryRenderJob($render->id, $render->uuid);
        $job->handle(app(\App\Story\Services\StoryRenderService::class));
        $job->handle(app(\App\Story\Services\StoryRenderService::class));

        $this->assertSame('completed', $render->fresh()->status);
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame('story.render.completed', $owner->notifications()->first()->type);
        $this->assertSame(0, $other->notifications()->count());
        Http::assertNothingSent();
    }

    public function test_a_failed_build_stores_a_safe_message_and_notifies_the_requester(): void
    {
        $this->media(true, true);
        [$owner, $project, $reel, $first] = $this->videos();
        $this->place($owner, $project, $reel, $first['version']->uuid);
        $render = $this->queuedRender($reel, $owner);

        (new ProcessStoryRenderJob($render->id, $render->uuid))
            ->handle(app(\App\Story\Services\StoryRenderService::class));

        $render->refresh();
        $this->assertSame('failed', $render->status);
        $this->assertStringNotContainsString('ffmpeg', (string) $render->error_message);
        $this->assertStringNotContainsString('\\', (string) $render->error_message);
        $this->assertStringNotContainsString('story/first.mp4', (string) $render->error_message);
        $this->assertSame('story.render.failed', $owner->notifications()->first()->type);
        Http::assertNothingSent();
    }

    public function test_a_broken_queue_falls_back_to_the_sync_render(): void
    {
        config([
            'story_render.queue.enabled' => true,
            'story_render.queue.connection' => 'missing-queue',
        ]);
        $this->media();
        [$owner, $project, $reel, $first] = $this->videos();
        $this->place($owner, $project, $reel, $first['version']->uuid);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->renders($project, $reel))
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.queued', false)
            ->assertJsonPath('data.fallback', 'sync');
        Http::assertNothingSent();
    }

    public function test_stale_renders_are_failed_or_requeued_and_dry_run_writes_nothing(): void
    {
        Queue::fake();
        [$owner, , $reel] = $this->videos();
        $stale = $this->queuedRender($reel, $owner);
        $stale->forceFill([
            'status' => 'rendering',
            'started_at' => now()->subHours(2),
        ])->save();

        $this->artisan('hn9:story-recover-renders')->assertSuccessful();
        $this->assertSame('failed', $stale->fresh()->status);
        $this->assertSame('Render was interrupted; please render again', $stale->fresh()->error_message);
        $this->assertSame(1, $owner->notifications()->count());

        $lost = $this->queuedRender($reel, $owner);
        $lost->forceFill(['queued_at' => now()->subHours(2)])->save();
        $this->artisan('hn9:story-recover-renders')->assertSuccessful();
        Queue::assertPushed(ProcessStoryRenderJob::class, 1);

        $untouched = $this->queuedRender($reel, $owner);
        $untouched->forceFill([
            'status' => 'rendering',
            'started_at' => now()->subHours(2),
        ])->save();
        $this->artisan('hn9:story-recover-renders', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('rendering', $untouched->fresh()->status);
        Queue::assertPushed(ProcessStoryRenderJob::class, 1);
        Http::assertNothingSent();
    }

    private function queuedRender(StoryReel $reel, User $owner): StoryFinalRender
    {
        $timelineId = \App\Story\Models\StoryTimeline::query()->where('story_reel_id', $reel->id)->value('id');
        if ($timelineId === null) {
            $timelineId = \App\Story\Models\StoryTimeline::query()->create(['story_reel_id' => $reel->id])->id;
        }

        return StoryFinalRender::query()->create([
            'story_timeline_id' => $timelineId,
            'story_reel_id' => $reel->id,
            'requested_by' => $owner->id,
            'status' => 'queued',
            'queued_at' => now(),
            'timeline_version' => hash('sha256', 'queued-'.$reel->id.'-'.microtime(true)),
            'timeline_snapshot' => ['clips' => []],
        ]);
    }

    private function place(User $owner, Project $project, StoryReel $reel, string $versionUuid): void
    {
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->timeline($project, $reel).'/clips', [
                'media_kind' => 'video',
                'source_id' => $versionUuid,
            ])
            ->assertCreated();
    }

    /**
     * @return array{0: User, 1: Project, 2: StoryReel, 3: array{scene: StoryScene, version: StorySceneVersion}}
     */
    private function videos(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $workspace = StoryWorkspace::factory()->create(['project_id' => $project->id]);
        $reel = StoryReel::factory()->create(['story_workspace_id' => $workspace->id]);
        $first = $this->storedVideo($workspace, $reel, 'story/first.mp4', 'first-bytes');

        return [$owner, $project, $reel, $first];
    }

    /**
     * @return array{scene: StoryScene, version: StorySceneVersion}
     */
    private function storedVideo(StoryWorkspace $workspace, StoryReel $reel, string $path, string $bytes): array
    {
        Storage::disk('videos')->put($path, $bytes);
        $scene = StoryScene::factory()->create([
            'story_reel_id' => $reel->id,
            'sequence' => 1,
        ]);
        $version = StorySceneVersion::query()->create([
            'story_scene_id' => $scene->id,
            'version' => 1,
            'status' => 'approved',
            'title' => 'Scene 1',
        ]);
        StoryVideoGenerationJob::factory()->create([
            'story_workspace_id' => $workspace->id,
            'story_reel_id' => $reel->id,
            'story_scene_id' => $scene->id,
            'status' => 'completed',
            'provider_metadata' => [
                'version_id' => $version->uuid,
                'storage' => ['disk' => 'videos', 'path' => $path, 'mime' => 'video/mp4'],
            ],
        ]);

        return ['scene' => $scene, 'version' => $version];
    }

    private function media(bool $installed = true, bool $fails = false): FakeStoryMediaToolkit
    {
        $media = new FakeStoryMediaToolkit($installed, $fails);
        $this->app->instance(StoryMediaToolkit::class, $media);

        return $media;
    }

    private function timeline(Project $project, StoryReel $reel): string
    {
        return "/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/timeline";
    }

    private function renders(Project $project, StoryReel $reel): string
    {
        return "/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/renders";
    }
}
