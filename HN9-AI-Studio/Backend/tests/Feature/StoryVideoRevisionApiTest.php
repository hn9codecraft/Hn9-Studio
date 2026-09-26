<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryVideoEngineInterface;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class StoryVideoRevisionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'story_video.real_provider.enabled' => true,
            'story_video.real_provider.durations' => [8],
            'ai.providers.gemini.enabled' => true,
            'ai.providers.gemini.api_key' => 'test-key',
            'ai.providers.gemini.video_models' => ['configured-video-model'],
            'ai.providers.gemini.video_default_model' => 'configured-video-model',
        ]);
    }

    public function test_edit_and_extend_submit_the_stored_video_and_keep_the_previous_file(): void
    {
        Storage::fake('videos');
        $source = 'story/source-scene.mp4';
        Storage::disk('videos')->put($source, 'original-scene-bytes');
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'name' => 'models/configured-video-model/operations/story-edit-1',
            ]),
        ]);
        [$owner, $project, $reel, $scene, $version] = $this->versionWithFile($source);
        $other = $this->otherScene($reel);
        $before = Storage::disk('videos')->get($source);

        $edited = $this->actingAs($owner, 'sanctum')
            ->postJson($this->versionUrl($project, $reel, $scene, $version).'/edit', [
                'instruction' => 'Make the street wet',
            ])
            ->assertCreated()
            ->assertJsonPath('data.job.capability', 'video_edit')
            ->assertJsonPath('data.output_url', null)
            ->assertJsonPath('data.parent_version_id', $version->uuid);

        $body = strtolower($edited->getContent() ?: '');
        $this->assertStringNotContainsString('test-key', $body);
        $this->assertStringNotContainsString('key=', $body);
        $this->assertSame($before, Storage::disk('videos')->get($source));
        $this->assertSame(0, StorySceneVersion::query()->where('story_scene_id', $other->id)->count());

        $newJob = StoryVideoGenerationJob::query()->where('capability', 'video_edit')->first();
        $this->assertNotNull($newJob);
        $this->assertNull($newJob->provider_metadata['storage'] ?? null);
        $encoded = base64_encode('original-scene-bytes');
        Http::assertSent(fn ($request): bool => str_contains($request->body(), $encoded)
            && str_contains($request->body(), 'Make the street wet'));

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->versionUrl($project, $reel, $scene, $version).'/edit', [
                'instruction' => 'Make the street wet',
            ])
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.id', $edited->json('data.id'));

        Http::assertSentCount(1);
        $this->assertSame(2, StorySceneVersion::query()->where('story_scene_id', $scene->id)->count());

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'name' => 'models/configured-video-model/operations/story-extend-1',
            ]),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->versionUrl($project, $reel, $scene, $version).'/extend', [
                'instruction' => 'Continue into the alley',
            ])
            ->assertCreated()
            ->assertJsonPath('data.job.capability', 'video_extend')
            ->assertJsonPath('data.output_url', null);

        $this->assertSame($before, Storage::disk('videos')->get($source));
        Http::assertSent(fn ($request): bool => str_contains($request->body(), $encoded)
            && str_contains($request->body(), 'Continue into the alley'));

        $this->actingAs($owner, 'sanctum')
            ->getJson($this->versionUrl($project, $reel, $scene, $version))
            ->assertOk()
            ->assertJsonPath('data.output_url', null)
            ->assertJsonPath('data.has_file', true);

        $this->actingAs($owner, 'sanctum')
            ->get($this->versionUrl($project, $reel, $scene, $version).'/file')
            ->assertOk();
    }

    public function test_missing_source_and_disabled_provider_store_nothing(): void
    {
        Storage::fake('videos');
        Http::fake();
        [$owner, $project, $reel, $scene, $version] = $this->versionWithFile('story/missing-source.mp4');
        Storage::disk('videos')->delete('story/missing-source.mp4');

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->versionUrl($project, $reel, $scene, $version).'/edit', [
                'instruction' => 'Change it',
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(1, StorySceneVersion::query()->where('story_scene_id', $scene->id)->count());
        $this->assertSame([], Storage::disk('videos')->allFiles());

        Storage::disk('videos')->put('story/disabled-source.mp4', 'still-here');
        [$owner, $project, $reel, $scene, $version] = $this->versionWithFile('story/disabled-source.mp4');
        config(['story_video.real_provider.enabled' => false]);
        $this->app->forgetInstance(StoryCapabilityRouterInterface::class);
        $this->app->forgetInstance(StoryVideoEngineInterface::class);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->versionUrl($project, $reel, $scene, $version).'/extend', [
                'instruction' => 'Keep going',
            ])
            ->assertStatus(501);

        Http::assertNothingSent();
        $this->assertSame('still-here', Storage::disk('videos')->get('story/disabled-source.mp4'));
        $this->assertSame(1, StorySceneVersion::query()->where('story_scene_id', $scene->id)->count());
    }

    public function test_non_owner_reviewer_and_anonymous_cannot_edit_or_read(): void
    {
        Storage::fake('videos');
        Storage::disk('videos')->put('story/private-source.mp4', 'private-bytes');
        Http::fake();
        [$owner, $project, $reel, $scene, $version] = $this->versionWithFile('story/private-source.mp4');
        $intruder = User::factory()->create();
        $reviewer = User::factory()->reviewer()->create();

        $this->actingAs($intruder, 'sanctum')
            ->postJson($this->versionUrl($project, $reel, $scene, $version).'/edit', [
                'instruction' => 'Steal the scene',
            ])
            ->assertForbidden();

        $this->actingAs($reviewer, 'sanctum')
            ->postJson($this->versionUrl($project, $reel, $scene, $version).'/extend', [
                'instruction' => 'Not allowed',
            ])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->getJson($this->versionUrl($project, $reel, $scene, $version))
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->get($this->versionUrl($project, $reel, $scene, $version).'/file')
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(1, StorySceneVersion::query()->where('story_scene_id', $scene->id)->count());
    }

    public function test_anonymous_cannot_edit_or_read_a_scene_version(): void
    {
        Storage::fake('videos');
        Storage::disk('videos')->put('story/anon-source.mp4', 'anon-bytes');
        Http::fake();
        [, $project, $reel, $scene, $version] = $this->versionWithFile('story/anon-source.mp4');

        $this->getJson($this->versionUrl($project, $reel, $scene, $version))->assertUnauthorized();
        $this->get($this->versionUrl($project, $reel, $scene, $version).'/file')->assertUnauthorized();
        $this->postJson($this->versionUrl($project, $reel, $scene, $version).'/edit', [
            'instruction' => 'No',
        ])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_admin_can_edit_another_project(): void
    {
        Storage::fake('videos');
        Storage::disk('videos')->put('story/admin-source.mp4', 'admin-source-bytes');
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'name' => 'models/configured-video-model/operations/story-admin-edit',
            ]),
        ]);
        [, $project, $reel, $scene, $version] = $this->versionWithFile('story/admin-source.mp4');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->versionUrl($project, $reel, $scene, $version).'/edit', [
                'instruction' => 'Admin edit',
            ])
            ->assertCreated()
            ->assertJsonPath('data.job.capability', 'video_edit')
            ->assertJsonPath('data.output_url', null);

        Http::assertSentCount(1);
    }

    /**
     * @return array{0: User, 1: Project, 2: StoryReel, 3: StoryScene, 4: StorySceneVersion}
     */
    private function versionWithFile(string $path): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $workspace = StoryWorkspace::factory()->create(['project_id' => $project->id]);
        $reel = StoryReel::factory()->create(['story_workspace_id' => $workspace->id]);
        $scene = StoryScene::factory()->create(['story_reel_id' => $reel->id, 'sequence' => 1]);
        $version = StorySceneVersion::query()->create([
            'story_scene_id' => $scene->id,
            'version' => 1,
            'status' => 'approved',
            'title' => $scene->title,
        ]);
        StoryVideoGenerationJob::factory()->create([
            'story_workspace_id' => $workspace->id,
            'story_reel_id' => $reel->id,
            'story_scene_id' => $scene->id,
            'capability' => 'text_to_video',
            'status' => 'completed',
            'provider_metadata' => [
                'version_id' => $version->uuid,
                'storage' => [
                    'disk' => 'videos',
                    'path' => $path,
                    'mime' => 'video/mp4',
                ],
            ],
        ]);

        return [$owner, $project, $reel, $scene, $version];
    }

    private function otherScene(StoryReel $reel): StoryScene
    {
        return StoryScene::factory()->create([
            'story_reel_id' => $reel->id,
            'sequence' => 2,
            'title' => 'Other',
        ]);
    }

    private function versionUrl(Project $project, StoryReel $reel, StoryScene $scene, StorySceneVersion $version): string
    {
        return "/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/scenes/{$scene->uuid}/versions/{$version->uuid}";
    }
}
