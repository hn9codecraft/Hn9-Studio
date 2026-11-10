<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\DTOs\ProviderConfigDTO;
use App\AI\Exceptions\ProviderApiException;
use App\AI\Providers\ElevenLabs\ElevenLabsClient;
use App\AI\Providers\ElevenLabs\ElevenLabsConfig;
use App\AI\Providers\ElevenLabs\ElevenLabsProvider;
use App\AI\Providers\ElevenLabs\ElevenLabsResponseNormalizer;
use App\AI\Providers\ElevenLabs\ElevenLabsTokenCounter;
use App\AI\Providers\ElevenLabs\ElevenLabsUsageCalculator;
use App\AI\Providers\ElevenLabs\ElevenLabsVoiceRegistry;
use App\AI\Requests\VoiceRequest;
use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ConfiguresElevenLabsSound;
use Tests\TestCase;

final class ElevenLabsTimestampsTest extends TestCase
{
    use ConfiguresElevenLabsSound;
    use RefreshDatabase;

    private const SPEECH = 'https://api.elevenlabs.io/v1/text-to-speech/*';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableElevenLabsSound();
        Storage::fake('voice');
    }

    public function test_timestamps_are_optional_and_do_not_change_the_speech_request(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/with-timestamps')) {
                return Http::response([
                    'audio_base64' => base64_encode('timed-audio'),
                    'alignment' => [
                        'characters' => ['H', 'i'],
                        'character_start_times_seconds' => [0.0, 0.1],
                        'character_end_times_seconds' => [0.1, 0.2],
                    ],
                ], 200, ['Content-Type' => 'application/json', 'request-id' => 'req-timed']);
            }

            return Http::response('plain-audio', 200, ['Content-Type' => 'audio/mpeg', 'request-id' => 'req-plain']);
        });

        $plain = $this->provider()->generateVoice(new VoiceRequest(input: 'Hello studio.'));
        $plainRequest = Http::recorded()[0][0];
        $this->assertInstanceOf(Request::class, $plainRequest);
        $this->assertSame('/v1/text-to-speech/voice-id-one', parse_url($plainRequest->url(), PHP_URL_PATH));
        $this->assertStringContainsString('output_format=mp3_44100_128', (string) parse_url($plainRequest->url(), PHP_URL_QUERY));
        $this->assertArrayNotHasKey('with_timestamps', $plainRequest->data());
        $this->assertStringStartsWith('data:audio/mpeg;base64,', $plain->audio);
        $this->assertArrayNotHasKey('alignment', $plain->raw);

        $timed = $this->provider()->generateVoice(new VoiceRequest(
            input: 'Hello studio.',
            options: ['with_timestamps' => true, 'style' => 0.2],
        ));
        $timedRequest = Http::recorded()[1][0];
        $this->assertSame('/v1/text-to-speech/voice-id-one/with-timestamps', parse_url($timedRequest->url(), PHP_URL_PATH));
        $this->assertStringContainsString('output_format=mp3_44100_128', (string) parse_url($timedRequest->url(), PHP_URL_QUERY));
        $this->assertArrayNotHasKey('with_timestamps', $timedRequest->data());
        $this->assertSame($plainRequest->data()['text'], $timedRequest->data()['text']);
        $this->assertSame($plainRequest->data()['model_id'], $timedRequest->data()['model_id']);
        $this->assertSame('data:audio/mpeg;base64,'.base64_encode('timed-audio'), $timed->audio);
        $this->assertSame(['H', 'i'], $timed->raw['alignment']['characters']);
    }

    public function test_invalid_timestamp_audio_is_rejected(): void
    {
        Http::fake([
            self::SPEECH => Http::response(['audio_base64' => '!!!'], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->expectException(ProviderApiException::class);
        $this->provider()->generateVoice(new VoiceRequest(input: 'Hello', options: ['with_timestamps' => true]));
    }

    public function test_a_new_voiceover_stores_alignment_unless_timestamps_or_budget_block_it(): void
    {
        $json = true;
        Http::fake(function (Request $request) use (&$json) {
            if ($json && str_contains($request->url(), '/with-timestamps')) {
                return Http::response([
                    'audio_base64' => base64_encode('voice-bytes'),
                    'alignment' => [
                        'characters' => ['H', 'i'],
                        'character_start_times_seconds' => [0, 0.2],
                        'character_end_times_seconds' => [0.2, 0.4],
                    ],
                ], 200, ['Content-Type' => 'application/json']);
            }

            return Http::response('voice-bytes', 200, ['Content-Type' => 'audio/mpeg']);
        });
        [$owner, $project, $reel, $scene] = $this->scene();
        $this->actingAs($owner, 'sanctum')->postJson($this->audioUrl($project, $reel, $scene), [
            'role' => 'narration',
            'prompt' => 'Hi',
        ])->assertCreated();

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/with-timestamps')
                && ! array_key_exists('with_timestamps', $request->data());
        });
        $sidecars = array_values(array_filter(
            Storage::disk('voice')->allFiles(),
            static fn (string $path): bool => str_starts_with($path, 'alignments/'),
        ));
        $this->assertCount(1, $sidecars);
        $stored = json_decode((string) Storage::disk('voice')->get($sidecars[0]), true);
        $this->assertSame(['H', 'i'], $stored['characters']);
        $this->assertArrayNotHasKey('audio_base64', $stored);

        config(['story_subtitles.timestamps_enabled' => false]);
        $json = false;
        $this->actingAs($owner, 'sanctum')->postJson($this->audioUrl($project, $reel, $scene), [
            'role' => 'dialogue',
            'prompt' => 'Hello again',
        ])->assertCreated();
        Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), '/with-timestamps'));
        $this->assertCount(1, array_filter(
            Storage::disk('voice')->allFiles(),
            static fn (string $path): bool => str_starts_with($path, 'alignments/'),
        ));

        StoryWorkspace::query()->where('project_id', $project->id)->firstOrFail()->forceFill([
            'budget_amount' => 0,
            'budget_currency' => 'INR',
        ])->save();
        $sent = count(Http::recorded());
        $this->actingAs($owner, 'sanctum')->postJson($this->audioUrl($project, $reel, $scene), [
            'role' => 'voice',
            'prompt' => 'This must not be spoken',
        ])->assertStatus(422)->assertJsonPath('error_code', 'story_budget_exceeded');
        $this->assertCount($sent, Http::recorded());
        $this->assertCount(1, array_filter(
            Storage::disk('voice')->allFiles(),
            static fn (string $path): bool => str_starts_with($path, 'alignments/'),
        ));
    }

    private function provider(): ElevenLabsProvider
    {
        $config = ElevenLabsConfig::fromProviderConfig(ProviderConfigDTO::fromArray('elevenlabs', [
            'api_key' => 'test-key',
            'base_url' => 'https://api.elevenlabs.io/v1',
            'default_model' => 'model-standard',
            'models' => ['model-standard'],
            'voices' => ['Rachel' => 'voice-id-one'],
            'default_voice' => 'Rachel',
            'output_format' => 'mp3_44100_128',
            'output_formats' => ['mp3_44100_128'],
            'voice_settings' => ['stability' => 0.5, 'similarity' => 0.75],
        ]));
        $usage = new ElevenLabsUsageCalculator($config);

        return new ElevenLabsProvider(
            new ElevenLabsClient($this->app->make(Factory::class), $config),
            new ElevenLabsVoiceRegistry($config),
            $usage,
            new ElevenLabsResponseNormalizer($usage),
            new ElevenLabsTokenCounter,
            $config,
        );
    }

    /**
     * @return array{0: User, 1: Project, 2: StoryReel, 3: StoryScene}
     */
    private function scene(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $workspace = StoryWorkspace::factory()->create(['project_id' => $project->id]);
        $reel = StoryReel::factory()->create(['story_workspace_id' => $workspace->id]);
        $scene = StoryScene::factory()->create(['story_reel_id' => $reel->id, 'sequence' => 1]);

        return [$owner, $project, $reel, $scene];
    }

    private function audioUrl(Project $project, StoryReel $reel, StoryScene $scene): string
    {
        return "/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/scenes/{$scene->uuid}/audio";
    }
}
