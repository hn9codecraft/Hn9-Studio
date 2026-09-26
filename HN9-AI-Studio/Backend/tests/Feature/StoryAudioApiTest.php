<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryVideoEngineInterface;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneAudio;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class StoryAudioApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'story_video.real_provider.enabled' => true,
            'story_video.real_provider.durations' => [8],
            'story_video.real_provider.audio_roles' => [
                'voice',
                'narration',
                'dialogue',
                'music',
                'sfx',
                'ambient',
                'generated',
            ],
            'ai.providers.gemini.enabled' => true,
            'ai.providers.gemini.api_key' => 'test-audio-key',
            'ai.providers.gemini.video_models' => ['configured-video-model'],
            'ai.providers.gemini.video_default_model' => 'configured-video-model',
        ]);
    }

    public function test_role_routing_lists_and_creates_audio_without_vendor_names(): void
    {
        Storage::fake('voice');
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['name' => 'models/configured-video-model/operations/story-audio-1'])
                ->push([
                    'done' => true,
                    'inlineData' => [
                        'mimeType' => 'audio/mpeg',
                        'data' => base64_encode('voice-bytes'),
                    ],
                ]),
        ]);

        [$owner, $project, $reel, $scene] = $this->sceneFixture();

        $roles = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/story/audio/roles')
            ->assertOk()
            ->json('data');

        $this->assertSame(
            ['voice', 'narration', 'dialogue', 'music', 'sfx', 'ambient', 'generated'],
            array_column($roles, 'role'),
        );

        $created = $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), [
                'role' => 'voice',
                'prompt' => 'Calm narrator for the opening',
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'voice')
            ->assertJsonPath('data.output_url', null)
            ->assertJsonPath('data.has_file', true);

        $body = strtolower($created->getContent() ?: '');
        $this->assertStringNotContainsString('test-audio-key', $body);
        $this->assertStringNotContainsString('gemini', $body);
        $this->assertStringNotContainsString('seedance', $body);

        $audioId = $created->json('data.id');
        $this->assertNotNull($audioId);
        $this->assertSame(1, StorySceneAudio::query()->where('role', 'voice')->count());
        $this->assertNotEmpty(Storage::disk('voice')->allFiles());

        $this->actingAs($owner, 'sanctum')
            ->getJson($this->audioUrl($project, $reel, $scene).'?role=voice')
            ->assertOk()
            ->assertJsonPath('data.0.role', 'voice');

        $this->actingAs($owner, 'sanctum')
            ->getJson($this->audioUrl($project, $reel, $scene).'/'.$audioId)
            ->assertOk()
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.output_url', null);

        $this->actingAs($owner, 'sanctum')
            ->get($this->audioUrl($project, $reel, $scene).'/'.$audioId.'/file')
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), [
                'role' => 'voice',
                'prompt' => 'Calm narrator for the opening',
            ])
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.id', $audioId);

        Http::assertSent(fn ($request): bool => str_contains($request->body(), '[voice]')
            && str_contains($request->body(), 'Calm narrator for the opening'));
    }

    public function test_unsupported_role_does_not_substitute_another_role(): void
    {
        Storage::fake('voice');
        Http::fake();
        config(['story_video.real_provider.audio_roles' => ['voice', 'narration']]);
        $this->app->forgetInstance(StoryCapabilityRouterInterface::class);
        $this->app->forgetInstance(StoryVideoEngineInterface::class);

        [$owner, $project, $reel, $scene] = $this->sceneFixture();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), [
                'role' => 'music',
                'prompt' => 'Soft piano',
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, StorySceneAudio::query()->count());
        $this->assertSame([], Storage::disk('voice')->allFiles());
    }

    public function test_missing_credential_stores_nothing(): void
    {
        Storage::fake('voice');
        Http::fake();
        [$owner, $project, $reel, $scene] = $this->sceneFixture();

        config(['story_video.real_provider.enabled' => false]);
        $this->app->forgetInstance(StoryCapabilityRouterInterface::class);
        $this->app->forgetInstance(StoryVideoEngineInterface::class);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), [
                'role' => 'dialogue',
                'prompt' => 'Hello there',
            ])
            ->assertStatus(501);

        Http::assertNothingSent();
        $this->assertSame(0, StorySceneAudio::query()->count());
        $this->assertSame([], Storage::disk('voice')->allFiles());
    }

    public function test_non_owner_reviewer_and_anonymous_cannot_create_or_read(): void
    {
        Storage::fake('voice');
        Storage::disk('voice')->put('story/private-audio.mp3', 'private-audio');
        Http::fake();
        [$owner, $project, $reel, $scene] = $this->sceneFixture();
        $audio = StorySceneAudio::query()->create([
            'story_workspace_id' => $reel->story_workspace_id,
            'story_scene_id' => $scene->id,
            'role' => 'ambient',
            'status' => 'completed',
            'prompt' => 'Wind',
            'disk' => 'voice',
            'path' => 'story/private-audio.mp3',
            'mime' => 'audio/mpeg',
        ]);
        $intruder = User::factory()->create();
        $reviewer = User::factory()->reviewer()->create();

        $this->actingAs($intruder, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), [
                'role' => 'sfx',
                'prompt' => 'Steal',
            ])
            ->assertForbidden();

        $this->actingAs($reviewer, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), [
                'role' => 'sfx',
                'prompt' => 'Not allowed',
            ])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->getJson($this->audioUrl($project, $reel, $scene).'/'.$audio->uuid)
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->get($this->audioUrl($project, $reel, $scene).'/'.$audio->uuid.'/file')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_anonymous_cannot_create_or_read_audio(): void
    {
        Storage::fake('voice');
        Http::fake();
        [, $project, $reel, $scene] = $this->sceneFixture();

        $this->getJson('/api/v1/story/audio/roles')->assertUnauthorized();
        $this->getJson($this->audioUrl($project, $reel, $scene))->assertUnauthorized();
        $this->postJson($this->audioUrl($project, $reel, $scene), [
            'role' => 'voice',
            'prompt' => 'No',
        ])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_admin_can_create_audio_on_another_project(): void
    {
        Storage::fake('voice');
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['name' => 'models/configured-video-model/operations/story-admin-audio'])
                ->push([
                    'done' => true,
                    'inlineData' => [
                        'mimeType' => 'audio/mpeg',
                        'data' => base64_encode('admin-audio'),
                    ],
                ]),
        ]);
        [, $project, $reel, $scene] = $this->sceneFixture();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), [
                'role' => 'generated',
                'prompt' => 'Admin cue',
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'generated')
            ->assertJsonPath('data.output_url', null);

        Http::assertSentCount(2);
    }

    /**
     * @return array{0: User, 1: Project, 2: StoryReel, 3: StoryScene}
     */
    private function sceneFixture(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $workspace = StoryWorkspace::factory()->create(['project_id' => $project->id]);
        $reel = StoryReel::factory()->create(['story_workspace_id' => $workspace->id]);
        $scene = StoryScene::factory()->create(['story_reel_id' => $reel->id, 'sequence' => 1]);
        $scene->setRelation('reel', $reel);

        return [$owner, $project, $reel, $scene];
    }

    private function audioUrl(Project $project, StoryReel $reel, StoryScene $scene): string
    {
        return "/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/scenes/{$scene->uuid}/audio";
    }
}
