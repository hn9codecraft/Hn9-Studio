<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StoryGenerationAttempt;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryStyleBible;
use App\Story\Models\StoryTimeline;
use App\Story\Models\StoryTimelineClip;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class CreativeStudioRefinementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_reports_every_generation_feature_as_not_connected_without_credentials(): void
    {
        Http::fake();
        config([
            'ai.providers.gemini.api_key' => '',
            'ai.providers.openai.api_key' => '',
        ]);
        [$owner, $project] = $this->scene();

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}")
            ->assertOk()
            ->assertJsonPath('data.connections.story_planning', false)
            ->assertJsonPath('data.connections.reference_images', false)
            ->assertJsonPath('data.connections.video.text', false)
            ->assertJsonPath('data.connections.video.image', false)
            ->assertJsonPath('data.connections.video.reference', false)
            ->assertJsonPath('data.connections.sound.roles', [])
            ->assertJsonPath('data.abilities.approve', true);

        $this->assertStringNotContainsString('api_key', (string) $response->getContent());
        Http::assertNothingSent();
    }

    public function test_connections_follow_configured_credentials_without_exposing_them(): void
    {
        Http::fake();
        $this->enableLiveVideo();
        [$owner, $project] = $this->scene();

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}")
            ->assertOk()
            ->assertJsonPath('data.connections.video.text', true)
            ->assertJsonPath('data.connections.video.reference', true);

        $this->assertStringNotContainsString('test-key', (string) $response->getContent());
        Http::assertNothingSent();
    }

    public function test_admin_can_approve_across_projects_and_other_users_cannot_open_the_studio(): void
    {
        Http::fake();
        [, $project] = $this->scene();

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}")
            ->assertOk()
            ->assertJsonPath('data.abilities.approve', true);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}")
            ->assertForbidden();
    }

    public function test_scene_video_from_a_description_links_the_job_to_a_new_version(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'name' => 'models/configured-video-model/operations/scene-op-1',
            ]),
        ]);
        $this->enableLiveVideo();
        [$owner, $project, $reel, $scene] = $this->scene();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson($this->sceneUrl($project, $reel, $scene).'/regenerate', [
                'mode' => 'text',
                'prompt' => 'Lantern light on wet cobblestones',
            ])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.output_url', null);

        $version = StorySceneVersion::query()->sole();
        $job = StoryVideoGenerationJob::query()->where('story_scene_id', $scene->id)->orderBy('id')->firstOrFail();
        $this->assertSame($version->uuid, $job->provider_metadata['version_id'] ?? null);
        $this->assertSame($job->uuid, $response->json('data.job.id'));

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/scene-status")
            ->assertOk()
            ->assertJsonPath('data.0.scene_id', $scene->uuid)
            ->assertJsonPath('data.0.version.id', $version->uuid)
            ->assertJsonPath('data.0.video.job_id', $job->uuid)
            ->assertJsonPath('data.0.video.has_file', false);
    }

    public function test_picture_modes_require_a_project_reference_before_anything_is_created(): void
    {
        Http::fake();
        $this->enableLiveVideo();
        [$owner, $project, $reel, $scene] = $this->scene();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->sceneUrl($project, $reel, $scene).'/regenerate', ['mode' => 'image'])
            ->assertStatus(422);
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->sceneUrl($project, $reel, $scene).'/regenerate', ['mode' => 'sideways'])
            ->assertStatus(422);

        $this->assertSame(0, StorySceneVersion::query()->count());
        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
        Http::assertNothingSent();
    }

    public function test_approving_a_scene_with_a_stored_video_puts_it_on_the_timeline_once(): void
    {
        Storage::fake('videos');
        Http::fake();
        [$owner, $project, $reel, $scene, $workspace] = $this->scene();
        $version = $this->storedVersion($workspace, $reel, $scene, 1, 'story/scene-1-v1.mp4');

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->sceneUrl($project, $reel, $scene).'/approve', [])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/timeline")
            ->assertOk()
            ->assertJsonCount(1, 'data.clips')
            ->assertJsonPath('data.clips.0.source_version_id', $version->uuid)
            ->assertJsonPath('data.clips.0.scene_id', $scene->uuid)
            ->assertJsonPath('data.clips.0.scene_title', 'Opening');

        $next = $this->storedVersion($workspace, $reel, $scene, 2, 'story/scene-1-v2.mp4');
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->sceneUrl($project, $reel, $scene).'/approve', [])
            ->assertOk();

        $this->assertSame(1, StoryTimelineClip::query()->count());
        $this->assertSame($next->id, (int) StoryTimelineClip::query()->sole()->story_scene_version_id);
        Http::assertNothingSent();
    }

    public function test_reel_listings_are_owner_only(): void
    {
        Http::fake();
        [$owner, $project, $reel] = $this->scene();
        $intruder = User::factory()->create();
        $urls = [
            "/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/scene-status",
            "/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/audio",
            "/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/renders",
        ];

        foreach ($urls as $url) {
            $this->actingAs($owner, 'sanctum')->getJson($url)->assertOk();
            $this->actingAs($intruder, 'sanctum')->getJson($url)->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_anonymous_scene_status_request_is_unauthorized(): void
    {
        [, $project, $reel] = $this->scene();

        $this->getJson("/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/scene-status")
            ->assertUnauthorized();
    }

    public function test_renders_list_newest_first_with_duration_and_no_storage_paths(): void
    {
        Http::fake();
        [$owner, $project, $reel] = $this->scene();
        $timeline = StoryTimeline::query()->create(['story_reel_id' => $reel->id]);
        foreach (['failed', 'completed'] as $status) {
            StoryFinalRender::query()->create([
                'story_timeline_id' => $timeline->id,
                'story_reel_id' => $reel->id,
                'status' => $status,
                'timeline_version' => hash('sha256', $status),
                'timeline_snapshot' => ['clips' => [['in_ms' => 0, 'out_ms' => 8000], ['in_ms' => 1000, 'out_ms' => 5000]]],
            ]);
        }

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/renders")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.0.duration_ms', 12000)
            ->assertJsonPath('data.0.clip_count', 2)
            ->assertJsonPath('data.0.has_file', false)
            ->assertJsonPath('data.0.latest_export', null);

        $this->assertNotNull($response->json('data.0.created_at'));
    }

    public function test_history_includes_not_connected_attempts_and_final_video_builds(): void
    {
        Http::fake();
        [$owner, $project, $reel, $scene] = $this->scene();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->sceneUrl($project, $reel, $scene).'/regenerate', [])
            ->assertStatus(501);
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->sceneUrl($project, $reel, $scene).'/audio', [
                'role' => 'narration',
                'prompt' => 'A calm narrator',
            ])
            ->assertStatus(501);
        $timeline = StoryTimeline::query()->create(['story_reel_id' => $reel->id]);
        StoryFinalRender::query()->create([
            'story_timeline_id' => $timeline->id,
            'story_reel_id' => $reel->id,
            'status' => 'failed',
            'error_code' => 'story_render_empty',
            'timeline_version' => hash('sha256', 'empty'),
            'timeline_snapshot' => ['clips' => []],
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/history")
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $kinds = array_column($response->json('data'), 'kind');
        sort($kinds);
        $this->assertSame(['audio', 'final_video', 'video'], $kinds);
        $video = collect($response->json('data'))->firstWhere('kind', 'video');
        $this->assertSame('not_connected', $video['status']);
        $this->assertSame($scene->uuid, $video['scene_id']);
        $this->assertSame('Opening', $video['scene_title']);
        $this->assertNull($video['cost']);
        $this->assertFalse($video['cost_reported']);
        $this->assertSame(2, StoryGenerationAttempt::query()->count());
        Http::assertNothingSent();
    }

    public function test_story_details_style_and_aspect_ratio_flow_into_look_and_feel(): void
    {
        Http::fake();
        [$owner, $project] = $this->scene();

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$project->uuid}/bible", [
                'concept' => 'A lantern keeper guards the last light in town.',
                'video_style' => 'Painted 2D animation',
                'aspect_ratio' => '9:16',
            ])
            ->assertOk();

        $style = StoryStyleBible::query()->sole();
        $this->assertSame('Painted 2D animation', $style->visual_style);
        $this->assertSame('9:16', $style->aspect_ratio);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$project->uuid}/bible", ['tone' => 'Warm'])
            ->assertOk();
        $this->assertSame('Painted 2D animation', $style->fresh()?->visual_style);
    }

    private function enableLiveVideo(): void
    {
        config([
            'story_video.real_provider.enabled' => true,
            'story_video.real_provider.durations' => [8],
            'ai.providers.gemini.enabled' => true,
            'ai.providers.gemini.api_key' => 'test-key',
            'ai.providers.gemini.video_models' => ['configured-video-model'],
            'ai.providers.gemini.video_default_model' => 'configured-video-model',
        ]);
    }

    private function storedVersion(StoryWorkspace $workspace, StoryReel $reel, StoryScene $scene, int $number, string $path): StorySceneVersion
    {
        Storage::disk('videos')->put($path, 'fake-mp4-'.$number);
        $version = StorySceneVersion::query()->create([
            'story_scene_id' => $scene->id,
            'version' => $number,
            'status' => 'pending_review',
            'title' => $scene->title,
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

        return $version;
    }

    /**
     * @return array{0: User, 1: Project, 2: StoryReel, 3: StoryScene, 4: StoryWorkspace}
     */
    private function scene(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $workspace = StoryWorkspace::factory()->create(['project_id' => $project->id]);
        $reel = StoryReel::factory()->create(['story_workspace_id' => $workspace->id, 'title' => 'Reel']);
        $scene = StoryScene::factory()->create([
            'story_reel_id' => $reel->id,
            'sequence' => 1,
            'title' => 'Opening',
            'visual_prompt' => 'A quiet street',
            'duration_seconds' => 8,
        ]);

        return [$owner, $project, $reel, $scene, $workspace];
    }

    private function sceneUrl(Project $project, StoryReel $reel, StoryScene $scene): string
    {
        return "/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/scenes/{$scene->uuid}";
    }
}
