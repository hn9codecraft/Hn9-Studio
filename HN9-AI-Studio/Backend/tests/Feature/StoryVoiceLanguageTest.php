<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProviderSetting;
use App\Models\User;
use App\Support\RuntimeProviderCredentials;
use App\Story\Models\StoryBible;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryWorkspace;
use Database\Seeders\AiProviderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ConfiguresElevenLabsSound;
use Tests\TestCase;

final class StoryVoiceLanguageTest extends TestCase
{
    use ConfiguresElevenLabsSound;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('voice');
        Http::preventStrayRequests();
        Http::fake([
            self::ELEVENLABS_SPEECH_URL => Http::response('voice-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);
        $this->enableElevenLabsSound();
    }

    public function test_english_uses_the_default_model_without_a_language_code(): void
    {
        config(['ai.providers.elevenlabs.default_model' => 'model-english']);
        config(['ai.providers.elevenlabs.models' => ['model-english', 'model-standard']]);
        [$user, $project, $scene] = $this->scene();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project, $scene), $this->body('en'))
            ->assertCreated()
            ->assertJsonPath('data.language', 'en')
            ->assertJsonPath('data.voice_model', 'model-english');

        Http::assertSent(fn ($request): bool => ($request->data()['model_id'] ?? null) === 'model-english'
            && ! array_key_exists('language_code', $request->data())
            && str_contains($request->url(), '/text-to-speech/voice-id-one'));
    }

    public function test_hindi_uses_the_multilingual_model_without_a_language_code(): void
    {
        [$user, $project, $scene] = $this->scene();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project, $scene), $this->body('hi'))
            ->assertCreated()
            ->assertJsonPath('data.voice_model', 'eleven_multilingual_v2');

        Http::assertSent(fn ($request): bool => ($request->data()['model_id'] ?? null) === 'eleven_multilingual_v2'
            && ! array_key_exists('language_code', $request->data()));
    }

    public function test_gujarati_uses_eleven_v3_and_sends_the_language_code(): void
    {
        config(['story_voice.languages.gu.voice_id' => 'gu-voice-99']);
        [$user, $project, $scene] = $this->scene();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project, $scene), $this->body('gu'))
            ->assertCreated()
            ->assertJsonPath('data.voice_model', 'eleven_v3');

        Http::assertSent(fn ($request): bool => ($request->data()['model_id'] ?? null) === 'eleven_v3'
            && ($request->data()['language_code'] ?? null) === 'gu'
            && str_contains($request->url(), '/text-to-speech/gu-voice-99'));
    }

    public function test_request_language_wins_over_the_bible_and_the_bible_wins_over_the_default(): void
    {
        [$user, $project, $scene] = $this->scene();
        StoryBible::factory()->create([
            'story_workspace_id' => $scene->reel->workspace->id,
            'language' => 'hi',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project, $scene), $this->body(null))
            ->assertCreated()
            ->assertJsonPath('data.language', 'hi')
            ->assertJsonPath('data.voice_model', 'eleven_multilingual_v2');

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project, $scene), $this->body('en', 'second-key'))
            ->assertCreated()
            ->assertJsonPath('data.language', 'en')
            ->assertJsonPath('data.voice_model', 'model-standard');
    }

    public function test_an_unsupported_language_is_rejected_before_the_budget_guard_or_any_http_call(): void
    {
        config(['story_voice.languages.gu.model' => 'eleven_multilingual_v2']);
        [$user, $project, $scene] = $this->scene();
        $scene->reel->workspace->forceFill(['budget_amount' => 0, 'budget_currency' => 'USD'])->save();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project, $scene), $this->body('gu'))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_voice_language_unsupported')
            ->assertJsonPath('message', 'Gujarati needs eleven_v3 or newer; set ELEVENLABS_MODEL_GU');

        Http::assertNothingSent();
    }

    public function test_an_unknown_model_is_allowed_when_the_caller_configured_it(): void
    {
        config(['story_voice.languages.en.model' => 'brand-new-model']);
        [$user, $project, $scene] = $this->scene();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project, $scene), $this->body('en'))
            ->assertCreated()
            ->assertJsonPath('data.voice_model', 'brand-new-model');

        Http::assertSent(fn ($request): bool => ($request->data()['model_id'] ?? null) === 'brand-new-model');
    }

    public function test_a_database_only_key_is_sent_and_never_logged(): void
    {
        config([
            'ai.providers.elevenlabs.api_key' => null,
            'hn9.runtime_credentials.enabled' => true,
        ]);
        $provider = app(AiProviderSeeder::class)->ensure('elevenlabs');
        ProviderSetting::query()->create([
            'ai_provider_id' => $provider->id,
            'key' => 'api_key',
            'value' => 'test-key-1234',
            'is_secret' => true,
            'environment' => (string) config('app.env'),
        ]);
        app(RuntimeProviderCredentials::class)->apply();
        $this->rebuildSoundPlatform();
        (new \App\Providers\AIServiceProvider($this->app))->boot();

        $lines = [];
        Log::listen(static function ($log) use (&$lines): void {
            $lines[] = $log->message.json_encode($log->context);
        });
        [$user, $project, $scene] = $this->scene();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project, $scene), $this->body('en'))
            ->assertCreated();

        Http::assertSent(fn ($request): bool => $request->hasHeader('xi-api-key', 'test-key-1234'));
        $this->assertStringNotContainsString('test-key-1234', implode("\n", $lines));
    }

    public function test_voice_languages_are_listed_without_voice_ids_or_keys(): void
    {
        config(['story_voice.languages.gu.voice_id' => 'secret-gu-voice']);
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/story/voice-languages')->assertOk();

        $this->assertSame('en', $response->json('data.default_language'));
        $this->assertTrue($response->json('data.languages.2.voice_configured'));
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('secret-gu-voice', $body);
        $this->assertStringNotContainsString('test-elevenlabs-key', $body);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/story/voice-languages')->assertUnauthorized();
    }

    public function test_a_zero_budget_still_blocks_a_supported_language(): void
    {
        [$user, $project, $scene] = $this->scene();
        $scene->reel->workspace->forceFill(['budget_amount' => 0, 'budget_currency' => 'USD'])->save();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project, $scene), $this->body('en'))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_budget_exceeded');

        Http::assertNothingSent();
    }

    public function test_a_request_without_language_keeps_the_default_voice_payload(): void
    {
        [$user, $project, $scene] = $this->scene();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project, $scene), $this->body(null))
            ->assertCreated();

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ($body['model_id'] ?? null) === 'model-standard'
                && ! array_key_exists('language_code', $body)
                && ! array_key_exists('story_model_id', $body)
                && str_contains($request->url(), '/text-to-speech/voice-id-one');
        });
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
            'duration_seconds' => 5,
        ]);

        return [$user, $project, $scene];
    }

    private function url(Project $project, StoryScene $scene): string
    {
        return '/api/v1/story/projects/'.$project->uuid.'/reels/'.$scene->reel->uuid.'/scenes/'.$scene->uuid.'/audio';
    }

    /** @return array<string, mixed> */
    private function body(?string $language, string $key = 'voice-key'): array
    {
        return array_filter([
            'prompt' => 'A calm narrator introduces the scene.',
            'role' => 'narration',
            'duration_seconds' => 4,
            'idempotency_key' => $key,
            'language' => $language,
        ], static fn ($value): bool => $value !== null);
    }
}
