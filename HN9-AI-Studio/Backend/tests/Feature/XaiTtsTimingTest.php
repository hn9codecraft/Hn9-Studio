<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ConfiguresElevenLabsSound;
use Tests\Support\ConfiguresXai;
use Tests\TestCase;

final class XaiTtsTimingTest extends TestCase
{
    use ConfiguresElevenLabsSound;
    use ConfiguresXai;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('voice');
        Http::preventStrayRequests();
    }

    public function test_english_and_hindi_store_character_timings(): void
    {
        $this->enableXai([
            'story_voice.providers.en' => 'xai',
            'story_voice.providers.hi' => 'xai',
        ]);
        Http::fake([
            'https://api.x.ai/v1/tts' => Http::response([
                'audio' => base64_encode('audio-bytes'),
                'content_type' => 'audio/mpeg',
                'duration' => 1.2,
                'audio_timestamps' => [
                    'graph_chars' => ['H', 'i'],
                    'graph_times' => [[0.0, 0.1], [0.1, 0.2]],
                ],
            ]),
        ]);
        [$user, $project, $scene] = $this->scene();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project, $scene), $this->body('en'))
            ->assertCreated();
        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project, $scene), $this->body('hi', 'voice-key-hi'))
            ->assertCreated();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.x.ai/v1/tts'
            && ($request->data()['language'] ?? null) === 'en'
            && ($request->data()['voice_id'] ?? null) === 'eve'
            && ($request->data()['with_timestamps'] ?? null) === true);
        Http::assertSent(fn ($request): bool => ($request->data()['language'] ?? null) === 'hi');

        $alignment = null;
        foreach (Storage::disk('voice')->allFiles() as $path) {
            if (str_starts_with($path, 'alignments/')) {
                $alignment = json_decode((string) Storage::disk('voice')->get($path), true);
                break;
            }
        }
        $this->assertIsArray($alignment);
        $this->assertSame(['H', 'i'], $alignment['characters']);
        $this->assertEquals([0.0, 0.1], $alignment['character_start_times_seconds']);
        $this->assertEquals([0.1, 0.2], $alignment['character_end_times_seconds']);
    }

    public function test_gujarati_stays_on_elevenlabs_when_the_flag_is_off(): void
    {
        $this->enableElevenLabsSound();
        $this->enableXai([
            'story_voice.providers.gu' => 'xai',
            'story_voice.xai_gujarati_experimental' => false,
            'ai.providers.xai.gujarati_experimental' => false,
        ]);
        Http::fake([
            'https://api.elevenlabs.io/*' => Http::response('voice-bytes', 200, ['Content-Type' => 'audio/mpeg']),
            'https://api.x.ai/*' => Http::response(['audio' => base64_encode('nope')], 200),
        ]);
        [$user, $project, $scene] = $this->scene();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project, $scene), $this->body('gu'))
            ->assertCreated();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.elevenlabs.io'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api.x.ai'));
    }

    /**
     * @return array{0: User, 1: Project, 2: StoryScene}
     */
    private function scene(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $workspace = StoryWorkspace::factory()->create(['project_id' => $project->id]);
        $reel = StoryReel::factory()->create(['story_workspace_id' => $workspace->id]);
        $scene = StoryScene::factory()->create([
            'story_reel_id' => $reel->id,
            'sequence' => 1,
            'duration_seconds' => 8,
        ]);

        return [$user, $project, $scene];
    }

    private function url(Project $project, StoryScene $scene): string
    {
        return '/api/v1/story/projects/'.$project->uuid.'/reels/'.$scene->reel->uuid.'/scenes/'.$scene->uuid.'/audio';
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $language, string $key = 'voice-key'): array
    {
        return [
            'prompt' => 'A calm narrator introduces the scene.',
            'role' => 'narration',
            'idempotency_key' => $key,
            'language' => $language,
        ];
    }
}
