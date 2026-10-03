<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Contracts\ProviderDispatcherInterface;
use App\AI\Exceptions\AllProvidersFailedException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Support\Capability;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use App\Story\Media\StoryMediaToolkit;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneAudio;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryTimelineClip;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ConfiguresElevenLabsSound;
use Tests\Support\FakeStoryMediaToolkit;
use Tests\TestCase;

/**
 * Creative Studio scene sound: ElevenLabs routing, versions, review and the approved-only timeline.
 * Every vendor request is answered by Http::fake(); no live call is made.
 */
final class StorySoundReviewApiTest extends TestCase
{
    use ConfiguresElevenLabsSound;
    use RefreshDatabase;

    private const GEMINI_URL = 'https://generativelanguage.googleapis.com/*';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('voice');
        Storage::fake('videos');
        config(['ai.retry.max_attempts' => 2]);
        $this->enableElevenLabsSound();
    }

    // ------------------------------------------------------------- routing --

    public function test_scene_sound_goes_to_elevenlabs_and_never_to_the_video_service(): void
    {
        $this->connectVideoService();
        $this->fakeSpeech();
        [$owner, $project, $reel, $scene] = $this->fixture();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), ['role' => 'narration', 'prompt' => 'The cave was silent.'])
            ->assertCreated()
            ->assertJsonPath('data.has_file', true);

        $job = StoryVideoGenerationJob::query()->sole();
        $this->assertSame('audio.elevenlabs', $job->provider_key);
        $this->assertSame('audio', $job->capability);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'api.elevenlabs.io'));
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'generativelanguage.googleapis.com'));

        $body = strtolower($response->getContent() ?: '');
        $this->assertStringNotContainsString('elevenlabs', $body);
        $this->assertStringNotContainsString(self::ELEVENLABS_TEST_KEY, $body);
    }

    public function test_sound_is_honestly_not_configured_even_when_the_video_service_is_connected(): void
    {
        $this->connectVideoService();
        $this->disableElevenLabsSound();
        Http::fake();
        [$owner, $project, $reel, $scene] = $this->fixture();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), ['role' => 'voice', 'prompt' => 'Hello'])
            ->assertStatus(501)
            ->assertJsonPath('error_code', 'GENERATION_NOT_ENABLED')
            ->assertJsonPath('message', 'Sound generation is not configured yet.');

        Http::assertNothingSent();
        $this->assertSame(0, StorySceneAudio::query()->count());
        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
        $this->assertSame([], Storage::disk('voice')->allFiles());

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}")
            ->assertOk()
            ->assertJsonPath('data.connections.sound.roles', [])
            ->assertJsonPath('data.connections.sound.voices', [])
            ->assertJsonPath('data.connections.video.text', true);
    }

    public function test_readiness_lists_voice_names_but_never_voice_ids_or_keys(): void
    {
        [$owner, $project] = $this->fixture();

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}")
            ->assertOk()
            ->assertJsonPath('data.connections.sound.roles', ['voice', 'narration', 'dialogue'])
            ->assertJsonPath('data.connections.sound.voices', ['Rachel', 'Adam'])
            ->assertJsonPath('data.connections.sound.default_voice', 'Rachel');

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('voice-id-one', $body);
        $this->assertStringNotContainsString(self::ELEVENLABS_TEST_KEY, $body);
    }

    public function test_scene_text_is_used_when_no_words_are_typed(): void
    {
        $this->fakeSpeech();
        [$owner, $project, $reel, $scene] = $this->fixture();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), ['role' => 'narration'])
            ->assertCreated()
            ->assertJsonPath('data.prompt', 'The cave was silent.');
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), ['role' => 'dialogue', 'prompt' => '  '])
            ->assertCreated()
            ->assertJsonPath('data.prompt', 'Aarav: I can do this.');

        Http::assertSent(static fn (Request $request): bool => ($request->data()['text'] ?? null) === 'The cave was silent.');
        Http::assertSent(static fn (Request $request): bool => ($request->data()['text'] ?? null) === 'Aarav: I can do this.');

        $job = StoryVideoGenerationJob::query()->orderBy('id')->first();
        $this->assertSame(['Aarav'], $job->request_payload['metadata']['characters'] ?? null);

        $silent = StoryScene::factory()->create(['story_reel_id' => $reel->id, 'sequence' => 2, 'narration' => null, 'dialogue' => []]);
        $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $silent), ['role' => 'voice'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Write the words that should be spoken.');
        Http::assertSentCount(2);
        $this->assertSame(0, StorySceneAudio::query()->where('story_scene_id', $silent->id)->count());
    }

    public function test_a_chosen_voice_is_used_and_an_unknown_voice_is_refused(): void
    {
        $this->fakeSpeech();
        [$owner, $project, $reel, $scene] = $this->fixture();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), ['role' => 'voice', 'prompt' => 'Hello', 'voice' => 'adam'])
            ->assertCreated();
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/text-to-speech/voice-id-two'));

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), ['role' => 'voice', 'prompt' => 'Hello again', 'voice' => 'Zed'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose one of the available voices.');
        Http::assertSentCount(1);
        $this->assertSame(1, StorySceneAudio::query()->count());
    }

    public function test_text_over_the_limit_is_refused_without_calling_the_service(): void
    {
        $this->enableElevenLabsSound(story: ['max_characters' => 20]);
        Http::fake();
        [$owner, $project, $reel, $scene] = $this->fixture();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), ['role' => 'voice', 'prompt' => str_repeat('word ', 10)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Keep the text under 20 characters.');

        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('voice')->allFiles());
    }

    // ------------------------------------------------------------ failures --

    /**
     * @return array<string, array{0: callable(): mixed, 1: string, 2: string, 3: int, 4: list<string>}>
     */
    public static function failures(): array
    {
        return [
            'missing or rejected credentials' => [
                static fn () => Http::response(['detail' => ['status' => 'invalid_api_key', 'message' => 'Invalid API key sk-vendor-secret']], 401),
                'authentication_failed',
                'The sound service rejected the saved credentials. An administrator needs to check them.',
                1,
                ['Invalid API key', 'sk-vendor-secret'],
            ],
            'rate limit' => [
                static fn () => Http::response(['detail' => 'Too many requests from 10.0.0.1'], 429),
                'rate_limited',
                'The sound service is busy right now. Try again in a moment.',
                2,
                ['Too many requests', '10.0.0.1'],
            ],
            'invalid request' => [
                static fn () => Http::response(['detail' => [['loc' => ['body', 'text'], 'msg' => 'text must not be empty', 'type' => 'value_error']]], 422),
                'invalid_input',
                'The sound service could not use this text. Change the words and try again.',
                2,
                ['text must not be empty', 'value_error'],
            ],
            'provider failure' => [
                static fn () => Http::response(['detail' => 'Internal server error at /srv/tts.py line 12'], 500),
                'upstream_error',
                'The sound service is temporarily unavailable. Try again later.',
                2,
                ['Internal server error', '/srv/tts.py'],
            ],
            'timeout' => [
                static fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds'),
                'timeout',
                'The sound service took too long to answer. Try again.',
                2,
                ['cURL error 28', '30000'],
            ],
            'unexpected response' => [
                static fn () => Http::response('<html>proxy error</html>', 200, ['Content-Type' => 'text/html']),
                'invalid_provider_response',
                'The sound service returned no audio. Try again.',
                1,
                ['proxy error', '<html>'],
            ],
        ];
    }

    /**
     * @param  callable(): mixed  $answer
     * @param  list<string>  $hidden
     */
    #[DataProvider('failures')]
    public function test_provider_failures_are_plain_language_and_store_no_file(
        callable $answer,
        string $code,
        string $message,
        int $requests,
        array $hidden,
    ): void {
        $calls = 0;
        Http::fake([self::ELEVENLABS_SPEECH_URL => static function () use (&$calls, $answer) {
            $calls++;

            return $answer();
        }]);
        [$owner, $project, $reel, $scene] = $this->fixture();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), ['role' => 'voice', 'prompt' => 'Hello there'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', strtoupper($code))
            ->assertJsonPath('message', $message);

        $this->assertSame($requests, $calls);
        $body = $response->getContent() ?: '';
        foreach ([...$hidden, self::ELEVENLABS_TEST_KEY, 'Stack trace', 'ElevenLabs'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        $audio = StorySceneAudio::query()->sole();
        $this->assertSame('failed', $audio->status);
        $this->assertSame(strtoupper($code), strtoupper((string) $audio->error_code));
        $this->assertSame($message, $audio->error_message);
        $this->assertSame('draft', $audio->review_status);
        $this->assertNotNull($audio->story_video_generation_job_id);
        $this->assertSame([], Storage::disk('voice')->allFiles());

        $listed = $this->actingAs($owner, 'sanctum')
            ->getJson($this->audioUrl($project, $reel, $scene))
            ->assertOk()
            ->assertJsonPath('data.0.error_message', $message);
        foreach ($hidden as $secret) {
            $this->assertStringNotContainsString($secret, $listed->getContent() ?: '');
        }
    }

    public function test_a_transient_failure_is_retried_and_then_succeeds(): void
    {
        Http::fake([
            self::ELEVENLABS_SPEECH_URL => Http::sequence()
                ->push(['detail' => 'temporarily overloaded'], 503)
                ->push('voice-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);
        [$owner, $project, $reel, $scene] = $this->fixture();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), ['role' => 'voice', 'prompt' => 'Hello there'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.has_file', true);

        Http::assertSentCount(2);
    }

    public function test_a_failed_version_can_be_tried_again_as_a_new_version(): void
    {
        Http::fake([
            self::ELEVENLABS_SPEECH_URL => Http::sequence()
                ->push(['detail' => 'bad'], 500)
                ->push(['detail' => 'bad'], 500)
                ->push('voice-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);
        [$owner, $project, $reel, $scene] = $this->fixture();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), ['role' => 'voice', 'prompt' => 'Hello there'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The sound service is temporarily unavailable. Try again later.');
        $first = StorySceneAudio::query()->sole();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene).'/'.$first->uuid.'/rework')
            ->assertCreated()
            ->assertJsonPath('data.version_label', 'Version B')
            ->assertJsonPath('data.parent_label', 'Version A')
            ->assertJsonPath('data.prompt', 'Hello there')
            ->assertJsonPath('data.review_status', 'pending_review');

        $this->assertSame('failed', $first->fresh()->status);
    }

    public function test_an_unexpected_internal_error_and_a_wrapped_rate_limit_are_mapped_safely(): void
    {
        [$owner, $project, $reel, $scene] = $this->fixture();
        $dispatcher = $this->createMock(ProviderDispatcherInterface::class);
        $dispatcher->method('voice')->willReturnOnConsecutiveCalls(
            $this->throwException(new RuntimeException('PDO secret dsn mysql://root:pw@db stack')),
            $this->throwException(AllProvidersFailedException::make(Capability::Voice, [], new ProviderRateLimitException('vendor said slow down'))),
        );
        $this->app->instance(ProviderDispatcherInterface::class, $dispatcher);
        $this->rebuildSoundPlatform();

        $first = $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), ['role' => 'voice', 'prompt' => 'One'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'UNKNOWN_PROVIDER_ERROR')
            ->assertJsonPath('message', 'Sound generation failed. Try again.');
        $this->assertStringNotContainsString('mysql://', $first->getContent() ?: '');

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), ['role' => 'voice', 'prompt' => 'Two'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'RATE_LIMITED');
    }

    // --------------------------------------------------------- idempotency --

    public function test_duplicate_submissions_make_one_version_and_one_request(): void
    {
        $this->fakeSpeech();
        [$owner, $project, $reel, $scene] = $this->fixture();
        $url = $this->audioUrl($project, $reel, $scene);

        $id = $this->actingAs($owner, 'sanctum')->postJson($url, ['role' => 'voice', 'prompt' => 'Hello'])->assertCreated()->json('data.id');
        $this->actingAs($owner, 'sanctum')->postJson($url, ['role' => 'voice', 'prompt' => 'Hello'])
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.id', $id);

        $reworked = $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$id.'/rework', ['prompt' => 'Hello, slower'])->assertCreated()->json('data.id');
        $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$id.'/rework', ['prompt' => 'Hello, slower'])
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.id', $reworked);

        Http::assertSentCount(2);
        $this->assertSame(2, StorySceneAudio::query()->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'story.sound.reworked')->count());
    }

    // ----------------------------------------------------------- lifecycle --

    public function test_versions_review_and_selection_never_overwrite_an_approved_version(): void
    {
        $this->fakeSpeech();
        [$owner, $project, $reel, $scene] = $this->fixture();
        $url = $this->audioUrl($project, $reel, $scene);

        $a = $this->actingAs($owner, 'sanctum')->postJson($url, ['role' => 'narration', 'prompt' => 'The cave was silent.'])
            ->assertCreated()
            ->assertJsonPath('data.version_label', 'Version A')
            ->assertJsonPath('data.review_status', 'pending_review')
            ->assertJsonPath('data.selected', false)
            ->json('data');

        $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$a['id'].'/request-changes', [])->assertStatus(422);
        $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$a['id'].'/request-changes', ['comment' => 'Slower please'])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'needs_rework')
            ->assertJsonPath('data.review_comment', 'Slower please');
        $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$a['id'].'/request-changes', ['comment' => 'Slower please'])
            ->assertOk()
            ->assertJsonPath('data.created', false);
        $this->assertSame(1, ActivityLog::query()->where('action', 'story.sound.changes_requested')->count());

        $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$a['id'].'/approve')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Changes were requested on this sound. Make a new version or approve another one.');

        $b = $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$a['id'].'/rework', ['prompt' => 'The cave... was silent.'])
            ->assertCreated()
            ->assertJsonPath('data.version_label', 'Version B')
            ->assertJsonPath('data.parent_label', 'Version A')
            ->assertJsonPath('data.review_status', 'pending_review')
            ->json('data');

        $aRow = StorySceneAudio::query()->where('uuid', $a['id'])->sole();
        $this->assertSame('needs_rework', $aRow->review_status);
        $this->assertSame('The cave was silent.', $aRow->prompt);
        $this->assertTrue(Storage::disk('voice')->exists((string) $aRow->path));

        $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$b['id'].'/approve', ['comment' => 'Good'])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'approved')
            ->assertJsonPath('data.selected', true);
        $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$b['id'].'/approve')
            ->assertOk()
            ->assertJsonPath('data.created', false);
        $this->assertSame(1, ActivityLog::query()->where('action', 'story.sound.approved')->count());
        $this->assertSame([$b['id']], $this->timelineAudio($project, $reel));

        $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$b['id'].'/request-changes', ['comment' => 'Hmm'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This sound is already approved. Make a new version to change it.');

        $bRow = StorySceneAudio::query()->where('uuid', $b['id'])->sole();
        $bPath = (string) $bRow->path;
        $c = $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$b['id'].'/rework', ['prompt' => 'The cave was very silent.'])
            ->assertCreated()
            ->assertJsonPath('data.version_label', 'Version C')
            ->json('data');
        $bRow->refresh();
        $this->assertSame('approved', $bRow->review_status);
        $this->assertTrue($bRow->isSelected());
        $this->assertSame($bPath, $bRow->path);
        $this->assertSame([$b['id']], $this->timelineAudio($project, $reel));

        $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$c['id'].'/approve')->assertOk()->assertJsonPath('data.selected', true);
        $this->assertFalse($bRow->fresh()->isSelected());
        $this->assertSame('approved', $bRow->fresh()->review_status);
        $this->assertSame([$c['id']], $this->timelineAudio($project, $reel));

        $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$b['id'].'/select')->assertOk()->assertJsonPath('data.selected', true);
        $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$b['id'].'/select')->assertOk()->assertJsonPath('data.created', false);
        $this->assertSame([$b['id']], $this->timelineAudio($project, $reel));
        $this->assertTrue(Storage::disk('voice')->exists($bPath));
        $this->assertSame(1, StorySceneAudio::query()->whereNotNull('selected_at')->count());

        $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$a['id'].'/select')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only an approved sound can be used in the video.');

        $versions = $this->actingAs($owner, 'sanctum')->getJson($url.'?role=narration')->assertOk()->json('data');
        $this->assertSame(['Version C', 'Version B', 'Version A'], array_column($versions, 'version_label'));
        $this->assertSame([false, true, false], array_column($versions, 'selected'));
    }

    public function test_only_the_owner_or_an_admin_can_review_sound(): void
    {
        $this->fakeSpeech();
        [$owner, $project, $reel, $scene] = $this->fixture();
        $url = $this->audioUrl($project, $reel, $scene);
        $id = $this->actingAs($owner, 'sanctum')->postJson($url, ['role' => 'voice', 'prompt' => 'Hello'])->json('data.id');

        $intruder = User::factory()->create();
        $reviewer = User::factory()->reviewer()->create();
        foreach ([$intruder, $reviewer] as $user) {
            foreach (['approve' => [], 'request-changes' => ['comment' => 'No'], 'rework' => [], 'select' => []] as $action => $payload) {
                $this->actingAs($user, 'sanctum')->postJson($url.'/'.$id.'/'.$action, $payload)->assertForbidden();
            }
        }
        $this->assertSame('pending_review', StorySceneAudio::query()->where('uuid', $id)->value('review_status'));

        [$other, $otherProject, $otherReel, $otherScene] = $this->fixture();
        $this->actingAs($other, 'sanctum')
            ->postJson($this->audioUrl($otherProject, $otherReel, $otherScene).'/'.$id.'/approve')
            ->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->postJson($url.'/'.$id.'/approve')->assertUnauthorized();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')->postJson($url.'/'.$id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.review_status', 'approved');
        $this->assertSame($admin->id, StorySceneAudio::query()->where('uuid', $id)->value('reviewed_by'));
        Http::assertSentCount(1);
    }

    // ------------------------------------------------------------ timeline --

    public function test_unapproved_sound_cannot_be_placed_on_the_timeline(): void
    {
        $this->fakeSpeech();
        [$owner, $project, $reel, $scene] = $this->fixture();
        $id = $this->actingAs($owner, 'sanctum')
            ->postJson($this->audioUrl($project, $reel, $scene), ['role' => 'voice', 'prompt' => 'Hello'])
            ->json('data.id');

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->timelineUrl($project, $reel).'/clips', ['media_kind' => 'audio', 'source_id' => $id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Approve this sound before adding it to the timeline.');
        $this->assertSame(0, StoryTimelineClip::query()->count());
    }

    public function test_approved_sound_plays_under_its_own_scene_and_the_final_video_mixes_it(): void
    {
        $this->fakeSpeech();
        $media = new FakeStoryMediaToolkit(true, false);
        $this->app->instance(StoryMediaToolkit::class, $media);
        [$owner, $project, $reel, $scene] = $this->fixture();
        $firstVersion = $this->storedVideo($reel, $scene, 'story/first.mp4');
        $second = StoryScene::factory()->create(['story_reel_id' => $reel->id, 'sequence' => 2]);
        $secondVersion = $this->storedVideo($reel, $second, 'story/second.mp4');

        $timeline = $this->timelineUrl($project, $reel);
        foreach ([$firstVersion, $secondVersion] as $version) {
            $this->actingAs($owner, 'sanctum')
                ->postJson($timeline.'/clips', ['media_kind' => 'video', 'source_id' => $version->uuid])
                ->assertCreated();
        }
        $clips = $this->actingAs($owner, 'sanctum')->getJson($timeline)->json('data.clips');
        $this->actingAs($owner, 'sanctum')
            ->postJson($timeline.'/transitions', [
                'from_clip_id' => $clips[0]['id'],
                'to_clip_id' => $clips[1]['id'],
                'type' => 'dissolve',
                'duration_ms' => 500,
            ])
            ->assertSuccessful();

        $url = $this->audioUrl($project, $reel, $scene);
        $id = $this->actingAs($owner, 'sanctum')->postJson($url, ['role' => 'narration'])->json('data.id');
        $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$id.'/approve')->assertOk();

        $after = $this->actingAs($owner, 'sanctum')->getJson($timeline)->json('data');
        $this->assertSame(['video', 'audio', 'video'], array_column($after['clips'], 'media_kind'));
        $this->assertSame($id, $after['clips'][1]['source_audio_id']);
        $this->assertSame($scene->uuid, $after['clips'][1]['scene_id']);
        $this->assertCount(1, $after['transitions']);
        $this->assertSame($after['clips'][1]['id'], $after['transitions'][0]['from_clip_id']);
        $this->assertSame($after['clips'][2]['id'], $after['transitions'][0]['to_clip_id']);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/renders")
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed');
        $build = $media->builds[0];
        $this->assertCount(2, $build['segments']);
        $this->assertSame('dissolve', $build['segments'][1]->transition);
        $this->assertCount(1, $build['overlays']);
        $this->assertSame(StorySceneAudio::query()->where('uuid', $id)->value('path'), $build['overlays'][0]->path);
        $this->assertSame(0.0, $build['overlays'][0]->startSeconds);
    }

    // ------------------------------------------------------------- history --

    public function test_history_shows_sound_generation_and_every_review_step(): void
    {
        Http::fake([
            self::ELEVENLABS_SPEECH_URL => Http::sequence()
                ->push('voice-a', 200, ['Content-Type' => 'audio/mpeg'])
                ->push('voice-b', 200, ['Content-Type' => 'audio/mpeg'])
                ->push(['detail' => 'bad'], 500)
                ->push(['detail' => 'bad'], 500),
        ]);
        [$owner, $project, $reel, $scene] = $this->fixture();
        $url = $this->audioUrl($project, $reel, $scene);

        $a = $this->actingAs($owner, 'sanctum')->postJson($url, ['role' => 'narration'])->json('data.id');
        $this->travel(1)->seconds();
        $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$a.'/request-changes', ['comment' => 'Warmer'])->assertOk();
        $this->travel(1)->seconds();
        $b = $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$a.'/rework', ['prompt' => 'Warmer words'])->json('data.id');
        $this->travel(1)->seconds();
        $this->actingAs($owner, 'sanctum')->postJson($url.'/'.$b.'/approve')->assertOk();
        $this->travel(1)->seconds();
        $this->actingAs($owner, 'sanctum')->postJson($url, ['role' => 'voice', 'prompt' => 'Fails'])->assertStatus(422);

        $history = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/history")
            ->assertOk()
            ->json('data');

        $events = array_values(array_filter($history, static fn (array $item): bool => $item['kind'] === 'sound_review'));
        $this->assertSame(
            ['version_created', 'changes_requested', 'reworked', 'approved', 'selected', 'version_created'],
            array_column($events, 'event'),
        );
        $this->assertSame(['Version A', 'Version A', 'Version B', 'Version B', 'Version B', 'Version A'], array_column($events, 'version_label'));
        $this->assertSame('Warmer', $events[1]['comment']);
        $this->assertSame($scene->uuid, $events[0]['scene_id']);

        $jobs = array_values(array_filter($history, static fn (array $item): bool => $item['kind'] === 'audio'));
        $this->assertSame(['completed', 'completed', 'failed'], array_column($jobs, 'status'));
        $this->assertNotNull($jobs[0]['completed_at']);
        $this->assertNotNull($jobs[2]['failed_at']);
        $this->assertSame('The sound service is temporarily unavailable. Try again later.', $jobs[2]['error_message']);

        $sequence = array_map(
            static fn (array $item): string => $item['kind'] === 'sound_review' ? $item['event'] : $item['kind'].':'.$item['status'],
            array_values(array_filter($history, static fn (array $item): bool => in_array($item['kind'], ['sound_review', 'audio'], true))),
        );
        $this->assertSame([
            'version_created', 'audio:completed',
            'changes_requested',
            'reworked', 'audio:completed',
            'approved', 'selected',
            'version_created', 'audio:failed',
        ], $sequence);
    }

    // ------------------------------------------------------------- helpers --

    private function fakeSpeech(): void
    {
        Http::fake([
            self::ELEVENLABS_SPEECH_URL => Http::response('voice-bytes', 200, ['Content-Type' => 'audio/mpeg']),
            self::GEMINI_URL => Http::response(['error' => ['message' => 'must not be called']], 500),
        ]);
    }

    private function connectVideoService(): void
    {
        config([
            'story_video.real_provider.enabled' => true,
            'story_video.real_provider.durations' => [8],
            'ai.providers.gemini.enabled' => true,
            'ai.providers.gemini.api_key' => 'test-video-key',
            'ai.providers.gemini.video_models' => ['configured-video-model'],
            'ai.providers.gemini.video_default_model' => 'configured-video-model',
        ]);
        $this->rebuildSoundPlatform();
    }

    /**
     * @return array{0: User, 1: Project, 2: StoryReel, 3: StoryScene}
     */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $workspace = StoryWorkspace::factory()->create(['project_id' => $project->id]);
        $reel = StoryReel::factory()->create(['story_workspace_id' => $workspace->id]);
        $scene = StoryScene::factory()->create([
            'story_reel_id' => $reel->id,
            'sequence' => 1,
            'narration' => 'The cave was silent.',
            'dialogue' => ['Aarav: I can do this.'],
            'characters' => ['Aarav'],
        ]);

        return [$owner, $project, $reel, $scene];
    }

    private function storedVideo(StoryReel $reel, StoryScene $scene, string $path): StorySceneVersion
    {
        Storage::disk('videos')->put($path, 'video-bytes');
        $version = StorySceneVersion::query()->create([
            'story_scene_id' => $scene->id,
            'version' => 1,
            'status' => 'approved',
            'title' => 'Scene '.$scene->sequence,
        ]);
        StoryVideoGenerationJob::factory()->create([
            'story_workspace_id' => $reel->story_workspace_id,
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
     * @return list<string>
     */
    private function timelineAudio(Project $project, StoryReel $reel): array
    {
        $clips = $this->getJson($this->timelineUrl($project, $reel))->assertOk()->json('data.clips');

        return array_values(array_map(
            static fn (array $clip): string => (string) $clip['source_audio_id'],
            array_filter($clips, static fn (array $clip): bool => $clip['media_kind'] === 'audio'),
        ));
    }

    private function audioUrl(Project $project, StoryReel $reel, StoryScene $scene): string
    {
        return "/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/scenes/{$scene->uuid}/audio";
    }

    private function timelineUrl(Project $project, StoryReel $reel): string
    {
        return "/api/v1/story/projects/{$project->uuid}/reels/{$reel->uuid}/timeline";
    }
}
