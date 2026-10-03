<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneAudio;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ConfiguresElevenLabsSound;
use Tests\TestCase;

final class StoryAudioApiTest extends TestCase
{
    use ConfiguresElevenLabsSound;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableElevenLabsSound();
    }

    public function test_role_routing_lists_and_creates_audio_without_vendor_names(): void
    {
        Storage::fake('voice');
        Http::fake([
            self::ELEVENLABS_SPEECH_URL => Http::response('voice-bytes', 200, ['Content-Type' => 'audio/mpeg']),
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
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.version_label', 'Version A')
            ->assertJsonPath('data.review_status', 'pending_review');

        $body = strtolower($created->getContent() ?: '');
        $this->assertStringNotContainsString(self::ELEVENLABS_TEST_KEY, $body);
        $this->assertStringNotContainsString('elevenlabs', $body);
        $this->assertStringNotContainsString('voice-id-one', $body);
        $this->assertStringNotContainsString('gemini', $body);
        $this->assertStringNotContainsString('seedance', $body);

        $audioId = $created->json('data.id');
        $this->assertNotNull($audioId);
        $this->assertSame(1, StorySceneAudio::query()->where('role', 'voice')->count());
        $this->assertNotEmpty(Storage::disk('voice')->allFiles());
        $this->assertSame('voice-bytes', Storage::disk('voice')->get((string) StorySceneAudio::query()->value('path')));

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

        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/text-to-speech/voice-id-one')
            && ($request->data()['text'] ?? null) === 'Calm narrator for the opening'
            && $request->hasHeader('xi-api-key', self::ELEVENLABS_TEST_KEY));
    }

    public function test_unsupported_role_does_not_substitute_another_role(): void
    {
        Storage::fake('voice');
        Http::fake();
        $this->enableElevenLabsSound(story: ['roles' => ['voice', 'narration']]);

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

        $this->disableElevenLabsSound();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), [
                'role' => 'dialogue',
                'prompt' => 'Hello there',
            ])
            ->assertStatus(501)
            ->assertJsonPath('message', 'Sound generation is not configured yet.');

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
            self::ELEVENLABS_SPEECH_URL => Http::response('admin-audio', 200, ['Content-Type' => 'audio/mpeg']),
        ]);
        [, $project, $reel, $scene] = $this->sceneFixture();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), [
                'role' => 'narration',
                'prompt' => 'Admin cue',
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'narration')
            ->assertJsonPath('data.output_url', null);

        Http::assertSentCount(1);
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
