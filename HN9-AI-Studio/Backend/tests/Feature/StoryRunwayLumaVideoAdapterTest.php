<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\LiveStoryVideoProviderAdapterInterface;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoInputType;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Media\StoryMediaToolkit;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryVideoDispatchService;
use App\Story\Video\Adapters\LiveStoryVideoAdapterFactory;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeStoryMediaToolkit;
use Tests\TestCase;

/**
 * Contract tests for the Runway and Luma adapters and live provider
 * selection. Every provider response is faked.
 */
final class StoryRunwayLumaVideoAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const RUNWAY = 'https://api.dev.runwayml.com/v1';

    private const LUMA = 'https://agents.lumalabs.ai/v1';

    private const LUMA_ID = '0b5c3f2e-6a1d-4f7b-9c2e-1d2a3b4c5d6e';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'story_video.real_provider.enabled' => false,
            'story_video.live_providers.seedance.enabled' => false,
            'story_video.live_providers.runway.enabled' => false,
            'story_video.live_providers.runway.api_key' => 'runway-secret-test-key',
            'story_video.live_providers.luma.enabled' => false,
            'story_video.live_providers.luma.api_key' => 'luma-secret-test-key',
        ]);
        $this->app->instance(StoryMediaToolkit::class, new FakeStoryMediaToolkit);
        Storage::fake('videos');
        Storage::fake('images');
    }

    public function test_runway_text_to_video_uses_the_versioned_api_and_runway_ratios(): void
    {
        config(['story_video.live_providers.runway.enabled' => true]);
        $this->fakeRunway([['id' => 'rw-task-1', 'status' => 'SUCCEEDED', 'output' => ['https://dnznrvs05pmza.cloudfront.net/out.mp4?_jwt=x'], 'cost' => ['credits' => 60]]]);
        [$user, $project] = $this->ownerProject();

        $jobId = $this->generate($user, $project, [
            'capability' => 'text_to_video',
            'duration_seconds' => 10,
            'aspect_ratio' => '9:16',
            'prompt' => 'A paper boat drifting down a rainy street',
        ])
            ->assertCreated()
            ->assertJsonPath('data.job.provider', 'video.runway')
            ->assertJsonPath('data.job.operation_id', 'rw-task-1')
            ->json('data.job.id');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::RUNWAY.'/text_to_video'
            && $request->hasHeader('X-Runway-Version', '2024-11-06')
            && $request->hasHeader('Authorization', 'Bearer runway-secret-test-key')
            && $request->data() === [
                'model' => 'gen4.5',
                'promptText' => 'A paper boat drifting down a rainy street',
                'ratio' => '720:1280',
                'duration' => 10,
            ]);

        $done = $this->show($user, $project, $jobId)->assertOk()->assertJsonPath('data.job.status', 'completed');
        $this->assertStringNotContainsString('_jwt', $done->getContent() ?: '');
        $job = StoryVideoGenerationJob::query()->where('uuid', $jobId)->firstOrFail();
        $this->assertSame(60, $job->provider_metadata['usage']['credits']);
        $this->assertSame('runway-video-bytes', Storage::disk('videos')->get($job->provider_metadata['storage']['path']));
    }

    public function test_runway_image_to_video_sends_the_picture_as_a_data_uri(): void
    {
        config(['story_video.live_providers.runway.enabled' => true]);
        $this->fakeRunway([['id' => 'rw-task-1', 'status' => 'PENDING']]);
        [$user, $project] = $this->ownerProject();
        $reference = $this->characterReference($project, 'owned/hero.png', 'hero-bytes');

        $this->generate($user, $project, [
            'capability' => 'image_to_video',
            'duration_seconds' => 5,
            'prompt' => 'He waves',
            'inputs' => [['type' => 'image', 'asset_id' => $reference->uuid]],
        ])->assertCreated();

        Http::assertSent(fn (Request $request): bool => $request->url() === self::RUNWAY.'/image_to_video'
            && $request->data()['promptImage'] === 'data:image/png;base64,'.base64_encode('hero-bytes')
            && $request->data()['promptText'] === 'He waves'
            && $request->data()['ratio'] === '1280:720');
    }

    public function test_runway_rejects_unsupported_shapes_and_extend_without_calling_runway(): void
    {
        config(['story_video.live_providers.runway.enabled' => true]);
        Http::fake();
        [$user, $project] = $this->ownerProject();

        $this->generate($user, $project, [
            'capability' => 'text_to_video',
            'duration_seconds' => 5,
            'aspect_ratio' => '1:1',
            'prompt' => 'Square',
        ])->assertStatus(422);

        $this->generate($user, $project, [
            'capability' => 'video_extend',
            'duration_seconds' => 5,
            'prompt' => 'Keep going',
        ])->assertStatus(501);

        Http::assertNothingSent();
    }

    public function test_runway_edit_sends_the_stored_scene_video(): void
    {
        config(['story_video.live_providers.runway.enabled' => true]);
        Http::fake([self::RUNWAY.'/video_to_video' => Http::response(['id' => 'rw-edit-1'])]);
        Storage::disk('videos')->put('p/scene.mp4', 'scene-bytes');

        $submission = $this->adapter('video.runway')->submit($this->request(StoryVideoCapability::VideoEdit, 'Make it night', [
            new StoryVideoInput(StoryVideoInputType::Video, metadata: ['disk' => 'videos', 'path' => 'p/scene.mp4', 'mime' => 'video/mp4']),
        ], 5));

        $this->assertSame('rw-edit-1', $submission->operationId);
        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'model' => 'aleph2',
            'promptText' => 'Make it night',
            'videoUri' => 'data:video/mp4;base64,'.base64_encode('scene-bytes'),
        ]);
    }

    public function test_runway_failure_codes_are_mapped_without_a_file(): void
    {
        config(['story_video.live_providers.runway.enabled' => true]);
        $this->fakeRunway([['id' => 'rw-task-1', 'status' => 'FAILED', 'failure' => 'blocked', 'failureCode' => 'SAFETY.INPUT.TEXT']]);
        [$user, $project] = $this->ownerProject();
        $jobId = $this->generate($user, $project, [
            'capability' => 'text_to_video',
            'duration_seconds' => 5,
            'prompt' => 'Something',
        ])->assertCreated()->json('data.job.id');

        $this->show($user, $project, $jobId)
            ->assertOk()
            ->assertJsonPath('data.job.status', 'failed')
            ->assertJsonPath('data.job.error_code', 'INVALID_INPUT');
        $this->assertSame([], Storage::disk('videos')->allFiles());
    }

    public function test_luma_text_to_video_creates_a_ray_generation_and_stores_the_result(): void
    {
        config(['story_video.live_providers.luma.enabled' => true]);
        $this->fakeLuma([
            ['id' => self::LUMA_ID, 'state' => 'processing'],
            ['id' => self::LUMA_ID, 'state' => 'completed', 'output' => [['type' => 'video', 'url' => 'https://storage.cdn-luma.com/out.mp4']]],
        ]);
        [$user, $project] = $this->ownerProject();

        $jobId = $this->generate($user, $project, [
            'capability' => 'text_to_video',
            'duration_seconds' => 10,
            'aspect_ratio' => '21:9',
            'prompt' => 'A desert caravan at sunset',
        ])
            ->assertCreated()
            ->assertJsonPath('data.job.provider', 'video.luma')
            ->json('data.job.id');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::LUMA.'/generations'
            && $request->hasHeader('Authorization', 'Bearer luma-secret-test-key')
            && $request->data() === [
                'model' => 'ray-3.2',
                'type' => 'video',
                'prompt' => 'A desert caravan at sunset',
                'video' => ['resolution' => '720p', 'duration' => '10s'],
                'aspect_ratio' => '21:9',
            ]);

        $this->show($user, $project, $jobId)->assertOk()->assertJsonPath('data.job.status', 'processing');
        $this->show($user, $project, $jobId)->assertOk()->assertJsonPath('data.job.status', 'completed');
        $job = StoryVideoGenerationJob::query()->where('uuid', $jobId)->firstOrFail();
        $this->assertSame('luma-video-bytes', Storage::disk('videos')->get($job->provider_metadata['storage']['path']));
    }

    public function test_luma_image_to_video_uses_a_five_second_start_frame(): void
    {
        config(['story_video.live_providers.luma.enabled' => true]);
        $this->fakeLuma([['id' => self::LUMA_ID, 'state' => 'queued']]);
        [$user, $project] = $this->ownerProject();
        $reference = $this->characterReference($project, 'owned/hero.png', 'hero-bytes');

        $this->generate($user, $project, [
            'capability' => 'image_to_video',
            'duration_seconds' => 5,
            'inputs' => [['type' => 'image', 'asset_id' => $reference->uuid]],
        ])->assertCreated();

        Http::assertSent(fn (Request $request): bool => $request->url() === self::LUMA.'/generations'
            && $request->data()['video']['start_frame'] === ['data' => base64_encode('hero-bytes'), 'media_type' => 'image/png']
            && $request->data()['video']['duration'] === '5s'
            && $request->data()['prompt'] !== '');
    }

    public function test_luma_edit_reuses_its_own_generation_and_uploads_other_videos(): void
    {
        config(['story_video.live_providers.luma.enabled' => true]);
        Http::fake([self::LUMA.'/generations' => Http::response(['id' => self::LUMA_ID], 201)]);
        Storage::disk('videos')->put('p/scene.mp4', 'scene-bytes');
        $adapter = $this->adapter('video.luma');

        $adapter->submit($this->request(StoryVideoCapability::VideoEdit, 'Make it snow', [
            new StoryVideoInput(StoryVideoInputType::Video, metadata: [
                'disk' => 'videos', 'path' => 'p/scene.mp4', 'mime' => 'video/mp4',
                'provider_key' => 'video.luma', 'operation_id' => self::LUMA_ID,
            ]),
        ], 5));
        $adapter->submit($this->request(StoryVideoCapability::VideoEdit, 'Make it snow', [
            new StoryVideoInput(StoryVideoInputType::Video, metadata: [
                'disk' => 'videos', 'path' => 'p/scene.mp4', 'mime' => 'video/mp4',
                'provider_key' => 'video.runway', 'operation_id' => 'rw-task-1',
            ]),
        ], 5));

        $bodies = Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->values()->all();
        $this->assertSame('video_edit', $bodies[0]['type']);
        $this->assertSame(['generation_id' => self::LUMA_ID], $bodies[0]['source']);
        $this->assertSame(['data' => base64_encode('scene-bytes'), 'media_type' => 'video/mp4'], $bodies[1]['source']);
        $this->assertSame(['auto_controls' => true], $bodies[1]['video']['edit']);
    }

    public function test_luma_extends_only_videos_it_created(): void
    {
        config(['story_video.live_providers.luma.enabled' => true]);
        Http::fake([self::LUMA.'/generations' => Http::response(['id' => self::LUMA_ID], 201)]);
        Storage::disk('videos')->put('p/scene.mp4', 'scene-bytes');
        $adapter = $this->adapter('video.luma');

        $adapter->submit($this->request(StoryVideoCapability::VideoExtend, 'The door opens', [
            new StoryVideoInput(StoryVideoInputType::Video, metadata: [
                'disk' => 'videos', 'path' => 'p/scene.mp4', 'provider_key' => 'video.luma', 'operation_id' => self::LUMA_ID,
            ]),
        ], 5));
        Http::assertSent(fn (Request $request): bool => $request->data()['video']['start_frame'] === ['generation_id' => self::LUMA_ID]
            && $request->data()['video']['duration'] === '5s');

        $this->expectException(StoryVideoEngineException::class);
        $adapter->submit($this->request(StoryVideoCapability::VideoExtend, 'The door opens', [
            new StoryVideoInput(StoryVideoInputType::Video, metadata: ['disk' => 'videos', 'path' => 'p/scene.mp4']),
        ], 5));
    }

    public function test_luma_failure_codes_are_mapped(): void
    {
        config(['story_video.live_providers.luma.enabled' => true]);
        $this->fakeLuma([['id' => self::LUMA_ID, 'state' => 'failed', 'failure_code' => 'budget_exhausted', 'failure_reason' => 'no credit']]);
        [$user, $project] = $this->ownerProject();
        $jobId = $this->generate($user, $project, [
            'capability' => 'text_to_video',
            'duration_seconds' => 5,
            'prompt' => 'Something',
        ])->assertCreated()->json('data.job.id');

        $this->show($user, $project, $jobId)
            ->assertOk()
            ->assertJsonPath('data.job.status', 'failed')
            ->assertJsonPath('data.job.error_code', 'QUOTA_EXCEEDED');
    }

    public function test_highest_priority_connected_service_is_chosen_and_others_can_be_requested(): void
    {
        config([
            'story_video.live_providers.runway.enabled' => true,
            'story_video.live_providers.luma.enabled' => true,
        ]);
        Http::fake([
            self::LUMA.'/generations' => Http::response(['id' => self::LUMA_ID], 201),
            self::RUNWAY.'/text_to_video' => Http::response(['id' => 'rw-task-1']),
        ]);
        [$user, $project] = $this->ownerProject();

        $this->generate($user, $project, ['capability' => 'text_to_video', 'duration_seconds' => 5, 'prompt' => 'Default'])
            ->assertCreated()
            ->assertJsonPath('data.job.provider', 'video.luma');
        $this->generate($user, $project, ['capability' => 'text_to_video', 'duration_seconds' => 5, 'prompt' => 'Runway please', 'preferred_provider' => 'video.runway'])
            ->assertCreated()
            ->assertJsonPath('data.job.provider', 'video.runway');
        $this->generate($user, $project, ['capability' => 'text_to_video', 'duration_seconds' => 5, 'prompt' => 'Demo', 'preferred_provider' => 'catalog.alpha'])
            ->assertCreated()
            ->assertJsonPath('data.job.provider', 'video.luma');

        $this->assertEqualsCanonicalizing(['video.luma', 'video.runway'], $this->app->make(StoryVideoDispatchService::class)->liveKeys());
    }

    public function test_providers_need_a_key_and_stay_off_in_tests_unless_enabled(): void
    {
        $this->assertFalse(LiveStoryVideoAdapterFactory::ready(['api_key' => ''], false));
        $this->assertFalse(LiveStoryVideoAdapterFactory::ready(['api_key' => null, 'enabled' => true], false));
        $this->assertTrue(LiveStoryVideoAdapterFactory::ready(['api_key' => 'k'], false));
        $this->assertFalse(LiveStoryVideoAdapterFactory::ready(['api_key' => 'k'], true));
        $this->assertTrue(LiveStoryVideoAdapterFactory::ready(['api_key' => 'k', 'enabled' => true], true));
        $this->assertFalse(LiveStoryVideoAdapterFactory::ready(['api_key' => 'k', 'enabled' => false], false));
    }

    public function test_provider_list_reports_only_real_services_as_healthy(): void
    {
        config(['story_video.live_providers.luma.enabled' => true]);
        Http::fake();
        [$user] = $this->ownerProject();

        $providers = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/story/video/providers')->assertOk()->json('data'));

        $this->assertTrue((bool) $providers->firstWhere('key', 'video.luma')['healthy']);
        $this->assertTrue((bool) $providers->firstWhere('key', 'video.luma')['connected']);
        foreach ($providers->where('connected', false) as $provider) {
            $this->assertFalse((bool) $provider['healthy'], (string) $provider['key']);
        }
        $this->assertStringNotContainsString('luma-secret-test-key', json_encode($providers->all()) ?: '');
        Http::assertNothingSent();
    }

    private function adapter(string $key): LiveStoryVideoProviderAdapterInterface
    {
        foreach ($this->app->make(StoryCapabilityRouterInterface::class)->adapters() as $adapter) {
            if ($adapter instanceof LiveStoryVideoProviderAdapterInterface && $adapter->key() === $key) {
                return $adapter;
            }
        }
        $this->fail("Adapter {$key} is not registered.");
    }

    /**
     * @param  list<StoryVideoInput>  $inputs
     */
    private function request(StoryVideoCapability $capability, string $prompt, array $inputs, int $duration): StoryVideoGenerationRequest
    {
        return new StoryVideoGenerationRequest(capability: $capability, prompt: $prompt, durationSeconds: $duration, inputs: $inputs);
    }

    /**
     * @param  list<array<string, mixed>>  $states
     */
    private function fakeRunway(array $states): void
    {
        Http::fake(function (Request $request) use (&$states) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'rw-task-1']);
            }
            if (str_contains($request->url(), 'cloudfront.net')) {
                return Http::response('runway-video-bytes');
            }

            return Http::response(count($states) > 1 ? array_shift($states) : $states[0]);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $states
     */
    private function fakeLuma(array $states): void
    {
        Http::fake(function (Request $request) use (&$states) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => self::LUMA_ID, 'state' => 'queued'], 201);
            }
            if (str_contains($request->url(), 'cdn-luma.com')) {
                return Http::response('luma-video-bytes');
            }

            return Http::response(count($states) > 1 ? array_shift($states) : $states[0]);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function generate(User $user, Project $project, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", $payload);
    }

    private function show(User $user, Project $project, string $jobId): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')->getJson("/api/v1/story/projects/{$project->uuid}/video/jobs/{$jobId}");
    }

    /**
     * @return array{0: User, 1: Project}
     */
    private function ownerProject(): array
    {
        $user = User::factory()->create();

        return [$user, Project::factory()->for($user)->create()];
    }

    private function characterReference(Project $project, string $path, string $bytes): StoryCharacterReference
    {
        $workspace = StoryWorkspace::factory()->create(['project_id' => $project->id]);
        $character = StoryCharacter::factory()->create(['story_workspace_id' => $workspace->id]);
        Storage::disk('images')->put($path, $bytes);

        return StoryCharacterReference::factory()->create([
            'story_character_id' => $character->id,
            'disk' => 'images',
            'path' => $path,
            'mime_type' => 'image/png',
        ]);
    }
}
