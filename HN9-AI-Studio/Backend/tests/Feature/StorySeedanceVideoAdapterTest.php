<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Media\StoryMediaToolkit;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryStyleBible;
use App\Story\Models\StoryStyleReference;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeStoryMediaToolkit;
use Tests\TestCase;

/**
 * Contract tests for the Seedance 2.0 (BytePlus ModelArk) adapter. Every
 * provider response is faked; no request leaves the test process.
 */
final class StorySeedanceVideoAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const TASKS = 'https://ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks';

    private const OUTPUT = 'https://ark-output.example.com/videos/cgt-1.mp4?X-Tos-Signature=abc';

    /** @var list<array<string, mixed>> */
    private array $taskStates = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'story_video.real_provider.enabled' => false,
            'story_video.live_providers.seedance.enabled' => true,
            'story_video.live_providers.seedance.api_key' => 'ark-secret-test-key',
            'story_video.live_providers.seedance.callback_base_url' => null,
            'story_video.live_providers.luma.enabled' => false,
            'story_video.live_providers.runway.enabled' => false,
        ]);
        $this->app->instance(StoryMediaToolkit::class, new FakeStoryMediaToolkit);
        Storage::fake('videos');
        Storage::fake('images');
    }

    public function test_text_to_video_creates_one_task_with_the_documented_payload(): void
    {
        $this->fakeSeedance();
        [$user, $project] = $this->ownerProject();
        $key = 'seedance-'.Str::uuid();

        $response = $this->generate($user, $project, [
            'capability' => 'text_to_video',
            'duration_seconds' => 8,
            'aspect_ratio' => '9:16',
            'audio_requested' => true,
            'prompt' => 'A lighthouse keeper watches a storm roll in',
            'idempotency_key' => $key,
        ])
            ->assertCreated()
            ->assertJsonPath('data.output_url', null)
            ->assertJsonPath('data.job.provider', 'video.seedance')
            ->assertJsonPath('data.job.model', 'dreamina-seedance-2-0-260128')
            ->assertJsonPath('data.job.operation_id', 'cgt-1')
            ->assertJsonPath('data.job.status', 'submitted');

        $this->assertStringNotContainsString('ark-secret-test-key', $response->getContent() ?: '');

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || $request->url() !== self::TASKS) {
                return false;
            }
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Bearer ark-secret-test-key')
                && $body['model'] === 'dreamina-seedance-2-0-260128'
                && $body['content'] === [['type' => 'text', 'text' => 'A lighthouse keeper watches a storm roll in']]
                && $body['ratio'] === '9:16'
                && $body['duration'] === 8
                && $body['resolution'] === '720p'
                && $body['generate_audio'] === true
                && $body['watermark'] === false
                && $body['execution_expires_after'] === 172800
                && ! array_key_exists('callback_url', $body);
        });

        $this->generate($user, $project, [
            'capability' => 'text_to_video',
            'duration_seconds' => 8,
            'prompt' => 'duplicate click',
            'idempotency_key' => $key,
        ])->assertOk()->assertJsonPath('data.created', false);

        $this->assertSame(1, $this->createCalls());
        $this->assertSame(1, StoryVideoGenerationJob::query()->count());
    }

    public function test_task_moves_from_queued_to_running_to_a_stored_private_file_with_usage(): void
    {
        $this->taskStates = [
            ['id' => 'cgt-1', 'status' => 'queued'],
            ['id' => 'cgt-1', 'status' => 'running'],
            [
                'id' => 'cgt-1',
                'status' => 'succeeded',
                'content' => ['video_url' => self::OUTPUT],
                'usage' => ['completion_tokens' => 108900, 'total_tokens' => 108900],
                'duration' => 8,
                'resolution' => '720p',
                'ratio' => '16:9',
                'framespersecond' => 24,
            ],
        ];
        $this->fakeSeedance();
        [$user, $project] = $this->ownerProject();
        $jobId = $this->startText($user, $project);

        $this->show($user, $project, $jobId)->assertOk()->assertJsonPath('data.job.status', 'processing');
        $this->show($user, $project, $jobId)->assertOk()->assertJsonPath('data.job.status', 'processing');
        $done = $this->show($user, $project, $jobId)
            ->assertOk()
            ->assertJsonPath('data.job.status', 'completed')
            ->assertJsonPath('data.output_url', null);

        $this->assertStringNotContainsString('X-Tos-Signature', $done->getContent() ?: '');
        $job = StoryVideoGenerationJob::query()->where('uuid', $jobId)->firstOrFail();
        $storage = $job->provider_metadata['storage'];
        $this->assertSame('videos', $storage['disk']);
        $this->assertStringStartsWith($project->uuid.'/', $storage['path']);
        $this->assertSame('seedance-video-bytes', Storage::disk('videos')->get($storage['path']));
        $this->assertSame(108900, $job->provider_metadata['usage']['completion_tokens']);
        $this->assertSame(8, $job->provider_metadata['usage']['output_duration']);
        $this->assertArrayNotHasKey('video_url', $job->provider_metadata);
        $this->assertStringNotContainsString('X-Tos-Signature', json_encode($job->provider_metadata) ?: '');

        $this->actingAs($user, 'sanctum')
            ->get("/api/v1/story/projects/{$project->uuid}/video/jobs/{$jobId}/file")
            ->assertOk();
        $this->assertSame(1, $this->createCalls());
    }

    public function test_image_to_video_sends_the_owned_picture_as_the_first_frame(): void
    {
        $this->fakeSeedance();
        [$user, $project] = $this->ownerProject();
        $reference = $this->characterReference($project, 'owned/hero.png', 'hero-image-bytes');

        $this->generate($user, $project, [
            'capability' => 'image_to_video',
            'duration_seconds' => 5,
            'prompt' => 'She turns toward the camera',
            'inputs' => [['type' => 'image', 'asset_id' => $reference->uuid]],
        ])->assertCreated();

        Http::assertSent(function (Request $request): bool {
            $content = $request->data()['content'] ?? [];

            return $request->url() === self::TASKS
                && ($content[1]['role'] ?? null) === 'first_frame'
                && ($content[1]['image_url']['url'] ?? null) === 'data:image/png;base64,'.base64_encode('hero-image-bytes')
                && $request->data()['ratio'] === 'adaptive';
        });
    }

    public function test_reference_to_video_sends_every_reference_picture(): void
    {
        $this->fakeSeedance();
        [$user, $project] = $this->ownerProject();
        $one = $this->styleReference($project, 'owned/look-1.png', 'look-one');
        $two = $this->styleReference($project, 'owned/look-2.png', 'look-two');

        $this->generate($user, $project, [
            'capability' => 'reference_to_video',
            'duration_seconds' => 6,
            'aspect_ratio' => '1:1',
            'prompt' => 'Keep this look',
            'inputs' => [
                ['type' => 'reference_image', 'asset_id' => $one->uuid],
                ['type' => 'reference_image', 'asset_id' => $two->uuid],
            ],
        ])->assertCreated();

        Http::assertSent(function (Request $request): bool {
            $roles = array_column(array_slice($request->data()['content'] ?? [], 1), 'role');

            return $request->url() === self::TASKS && $roles === ['reference_image', 'reference_image'];
        });
    }

    public function test_one_foreign_reference_among_several_rejects_the_request(): void
    {
        $this->fakeSeedance();
        [$user, $project] = $this->ownerProject();
        $owned = $this->styleReference($project, 'owned/look.png', 'look');
        $foreign = $this->styleReference(Project::factory()->create(), 'foreign/look.png', 'foreign');

        $this->generate($user, $project, [
            'capability' => 'reference_to_video',
            'duration_seconds' => 6,
            'prompt' => 'Mixed',
            'inputs' => [
                ['type' => 'reference_image', 'asset_id' => $owned->uuid],
                ['type' => 'reference_image', 'asset_id' => $foreign->uuid],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, $this->createCalls());
        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
    }

    public function test_reference_pictures_are_limited_to_720p_and_nine_pictures_without_calling_the_service(): void
    {
        $this->fakeSeedance();
        [$user, $project] = $this->ownerProject();
        $reference = $this->styleReference($project, 'owned/look.png', 'look');

        $this->generate($user, $project, [
            'capability' => 'reference_to_video',
            'duration_seconds' => 6,
            'resolution' => '1080p',
            'prompt' => 'Too sharp',
            'inputs' => [['type' => 'reference_image', 'asset_id' => $reference->uuid]],
        ])->assertStatus(422);

        $inputs = [];
        for ($index = 0; $index < 10; $index++) {
            $inputs[] = ['type' => 'reference_image', 'asset_id' => $this->styleReference($project, "owned/many-{$index}.png", "many-{$index}")->uuid];
        }
        $this->generate($user, $project, [
            'capability' => 'reference_to_video',
            'duration_seconds' => 6,
            'prompt' => 'Too many',
            'inputs' => $inputs,
        ])->assertStatus(422);

        $this->assertSame(0, $this->createCalls());
    }

    public function test_edit_without_signed_cloud_storage_is_refused_before_any_call(): void
    {
        $this->fakeSeedance();
        [$user, $project] = $this->ownerProject();

        $this->generate($user, $project, [
            'capability' => 'video_edit',
            'duration_seconds' => 8,
            'prompt' => 'Edit without signed storage',
        ])->assertStatus(501)->assertJsonPath('error_code', 'GENERATION_NOT_ENABLED');

        $this->assertSame(0, $this->createCalls());
    }

    public function test_short_scenes_are_rounded_up_to_the_shortest_supported_clip(): void
    {
        $this->fakeSeedance();
        [$user, $project] = $this->ownerProject();

        $this->generate($user, $project, [
            'capability' => 'text_to_video',
            'duration_seconds' => 2,
            'prompt' => 'A blink',
        ])->assertCreated()->assertJsonPath('data.units', [4]);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::TASKS && $request->data()['duration'] === 4);
    }

    public function test_failed_task_is_terminal_with_a_friendly_moderation_message(): void
    {
        $this->taskStates = [[
            'id' => 'cgt-1',
            'status' => 'failed',
            'error' => ['code' => 'OutputVideoSensitiveContentDetected', 'message' => 'The output may contain sensitive content.'],
        ]];
        $this->fakeSeedance();
        [$user, $project] = $this->ownerProject();
        $jobId = $this->startText($user, $project);

        $this->show($user, $project, $jobId)
            ->assertOk()
            ->assertJsonPath('data.job.status', 'failed')
            ->assertJsonPath('data.job.error_code', 'INVALID_INPUT');

        $job = StoryVideoGenerationJob::query()->where('uuid', $jobId)->firstOrFail();
        $this->assertStringContainsString('content rules', (string) $job->error_message);
        $this->assertSame([], Storage::disk('videos')->allFiles());
        $this->assertSame(1, $this->createCalls());
    }

    public function test_expired_and_cancelled_tasks_fail_without_a_file(): void
    {
        foreach (['expired' => 'TIMEOUT', 'cancelled' => 'UPSTREAM_ERROR'] as $status => $code) {
            $this->taskStates = [['id' => 'cgt-1', 'status' => $status]];
            $this->fakeSeedance();
            [$user, $project] = $this->ownerProject();
            $jobId = $this->startText($user, $project);

            $this->show($user, $project, $jobId)
                ->assertOk()
                ->assertJsonPath('data.job.status', 'failed')
                ->assertJsonPath('data.job.error_code', $code);
        }

        $this->assertSame([], Storage::disk('videos')->allFiles());
    }

    public function test_quota_failure_is_mapped_to_quota_exceeded(): void
    {
        $this->taskStates = [[
            'id' => 'cgt-1',
            'status' => 'failed',
            'error' => ['code' => 'AccountOverdueError', 'message' => 'Account balance is insufficient.'],
        ]];
        $this->fakeSeedance();
        [$user, $project] = $this->ownerProject();
        $jobId = $this->startText($user, $project);

        $this->show($user, $project, $jobId)->assertOk()->assertJsonPath('data.job.status', 'failed');
        $this->assertContains(
            StoryVideoGenerationJob::query()->where('uuid', $jobId)->value('error_code'),
            ['QUOTA_EXCEEDED', 'UPSTREAM_ERROR'],
        );
    }

    public function test_finished_task_without_a_video_address_is_not_marked_complete(): void
    {
        $this->taskStates = [['id' => 'cgt-1', 'status' => 'succeeded', 'content' => []]];
        $this->fakeSeedance();
        [$user, $project] = $this->ownerProject();
        $jobId = $this->startText($user, $project);

        $this->show($user, $project, $jobId)
            ->assertOk()
            ->assertJsonPath('data.job.status', 'failed')
            ->assertJsonPath('data.job.error_code', 'INVALID_PROVIDER_RESPONSE');
        $this->assertSame([], Storage::disk('videos')->allFiles());
    }

    public function test_rejected_credentials_fail_the_job_without_leaking_the_key(): void
    {
        Http::fake([
            self::TASKS => Http::response(['error' => ['code' => 'AuthenticationError', 'message' => 'invalid key ark-secret-test-key']], 401),
        ]);
        [$user, $project] = $this->ownerProject();

        $response = $this->generate($user, $project, [
            'capability' => 'text_to_video',
            'duration_seconds' => 5,
            'prompt' => 'Bad key',
        ]);

        $this->assertContains($response->status(), [401, 403, 422, 502, 503]);
        $this->assertStringNotContainsString('ark-secret-test-key', $response->getContent() ?: '');
        $job = StoryVideoGenerationJob::query()->firstOrFail();
        $this->assertSame('failed', $job->status);
        $this->assertStringNotContainsString('ark-secret-test-key', (string) $job->error_message);
    }

    public function test_rate_limited_status_checks_are_retried_without_resubmitting(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'cgt-1']);
            }

            return Http::response(['error' => ['code' => 'RateLimitExceeded', 'message' => 'slow down']], 429);
        });
        [$user, $project] = $this->ownerProject();
        $jobId = $this->startText($user, $project);

        $this->show($user, $project, $jobId)
            ->assertOk()
            ->assertJsonPath('data.job.status', 'submitted')
            ->assertJsonPath('data.job.failed_checks', 1)
            ->assertJsonPath('data.job.recovering', true);

        $this->assertSame(1, $this->createCalls());
    }

    public function test_status_callback_only_prompts_a_fresh_check_with_the_right_token(): void
    {
        config(['story_video.live_providers.seedance.callback_base_url' => 'https://studio.example.com']);
        $this->taskStates = [[
            'id' => 'cgt-1',
            'status' => 'succeeded',
            'content' => ['video_url' => self::OUTPUT],
        ]];
        $this->fakeSeedance();
        [$user, $project] = $this->ownerProject();
        $jobId = $this->startText($user, $project);

        $callback = null;
        Http::assertSent(function (Request $request) use (&$callback): bool {
            if ($request->url() !== self::TASKS) {
                return false;
            }
            $callback = $request->data()['callback_url'] ?? null;

            return is_string($callback);
        });
        $this->assertStringStartsWith('https://studio.example.com/api/v1/story/video-callbacks/'.$jobId.'/', (string) $callback);
        $token = substr((string) $callback, strrpos((string) $callback, '/') + 1);
        $job = StoryVideoGenerationJob::query()->where('uuid', $jobId)->firstOrFail();
        $this->assertSame(hash('sha256', $token), $job->provider_metadata['callback_token_hash']);
        $this->assertStringNotContainsString($token, json_encode($job->provider_metadata) ?: '');

        $forged = str_repeat('a', 48);
        $this->postJson("/api/v1/story/video-callbacks/{$jobId}/{$forged}", ['status' => 'succeeded', 'content' => ['video_url' => 'https://evil.example.com/x.mp4']])
            ->assertOk()
            ->assertExactJson(['received' => true]);
        $this->assertSame('submitted', StoryVideoGenerationJob::query()->where('uuid', $jobId)->value('status'));

        $this->postJson("/api/v1/story/video-callbacks/{$jobId}/{$token}", ['status' => 'succeeded', 'content' => ['video_url' => 'https://evil.example.com/x.mp4']])
            ->assertOk()
            ->assertExactJson(['received' => true]);

        $job->refresh();
        $this->assertSame('completed', $job->status);
        $this->assertSame('seedance-video-bytes', Storage::disk('videos')->get($job->provider_metadata['storage']['path']));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'evil.example.com'));
    }

    public function test_long_scene_is_made_in_parts_one_after_another_and_joined(): void
    {
        $media = new FakeStoryMediaToolkit;
        $this->app->instance(StoryMediaToolkit::class, $media);
        $created = 0;
        Http::fake(function (Request $request) use (&$created) {
            if ($request->method() === 'POST') {
                $created++;

                return Http::response(['id' => 'cgt-part-'.$created]);
            }
            if (str_starts_with($request->url(), 'https://ark-output.example.com/')) {
                return Http::response('part-bytes-'.basename(parse_url($request->url(), PHP_URL_PATH), '.mp4'));
            }
            $id = basename(parse_url($request->url(), PHP_URL_PATH));

            return Http::response([
                'id' => $id,
                'status' => 'succeeded',
                'content' => ['video_url' => 'https://ark-output.example.com/'.$id.'.mp4'],
            ]);
        });
        [$user, $project] = $this->ownerProject();

        $response = $this->generate($user, $project, [
            'capability' => 'text_to_video',
            'duration_seconds' => 30,
            'aspect_ratio' => '16:9',
            'prompt' => 'A long chase through the market',
        ])->assertCreated();

        $this->assertSame([15, 15], $response->json('data.units'));
        $jobId = $response->json('data.job.id');
        $this->assertSame(1, $created);

        $this->show($user, $project, $jobId)->assertOk()->assertJsonPath('data.job.status', 'processing');
        $this->assertSame(2, $created);
        $this->show($user, $project, $jobId)->assertOk()->assertJsonPath('data.job.status', 'completed');
        $this->assertSame(2, $created);

        $this->assertCount(1, $media->builds);
        $build = $media->builds[0];
        $this->assertSame(30.0, $build['max']);
        $this->assertSame('16:9', $build['aspect']);
        $this->assertCount(2, $build['segments']);

        $primary = StoryVideoGenerationJob::query()->where('uuid', $jobId)->firstOrFail();
        $this->assertSame(2, $primary->provider_metadata['joined_parts']);
        $this->assertSame(
            'built:part-bytes-cgt-part-1|part-bytes-cgt-part-2|',
            Storage::disk('videos')->get($primary->provider_metadata['storage']['path']),
        );
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains((string) ($request->data()['content'][0]['text'] ?? ''), 'Part 2 of 2'));
    }

    public function test_a_failed_part_fails_the_whole_scene_honestly(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                static $n = 0;
                $n++;

                return Http::response(['id' => 'cgt-part-'.$n]);
            }
            if (str_starts_with($request->url(), 'https://ark-output.example.com/')) {
                return Http::response('part-bytes');
            }
            $id = basename(parse_url($request->url(), PHP_URL_PATH));

            return Http::response($id === 'cgt-part-1'
                ? ['id' => $id, 'status' => 'succeeded', 'content' => ['video_url' => 'https://ark-output.example.com/1.mp4']]
                : ['id' => $id, 'status' => 'failed', 'error' => ['code' => 'InternalServiceError', 'message' => 'boom']]);
        });
        [$user, $project] = $this->ownerProject();
        $jobId = $this->generate($user, $project, [
            'capability' => 'text_to_video',
            'duration_seconds' => 30,
            'prompt' => 'Long scene',
        ])->assertCreated()->json('data.job.id');

        $this->show($user, $project, $jobId)->assertOk()->assertJsonPath('data.job.status', 'processing');
        $failed = $this->show($user, $project, $jobId)->assertOk()->assertJsonPath('data.job.status', 'failed');

        $this->assertStringContainsString('Part 2 of 2', (string) $failed->json('data.job.error_message'));
        $primary = StoryVideoGenerationJob::query()->where('uuid', $jobId)->firstOrFail();
        $this->assertArrayNotHasKey('storage', (array) $primary->provider_metadata);
    }

    public function test_seedance_is_not_registered_without_a_key_or_when_disabled(): void
    {
        config([
            'story_video.live_providers.seedance.api_key' => '',
        ]);
        Http::fake();
        [$user, $project] = $this->ownerProject();

        $this->generate($user, $project, [
            'capability' => 'text_to_video',
            'duration_seconds' => 8,
            'prompt' => 'No service',
        ])->assertStatus(501)->assertJsonPath('error_code', 'GENERATION_NOT_ENABLED');

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function generate(User $user, Project $project, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", $payload);
    }

    private function show(User $user, Project $project, string $jobId): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/video/jobs/{$jobId}");
    }

    private function startText(User $user, Project $project): string
    {
        return (string) $this->generate($user, $project, [
            'capability' => 'text_to_video',
            'duration_seconds' => 8,
            'prompt' => 'A quiet harbour at dawn',
        ])->assertCreated()->json('data.job.id');
    }

    private function fakeSeedance(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && $request->url() === self::TASKS) {
                return Http::response(['id' => 'cgt-1']);
            }
            if (str_starts_with($request->url(), 'https://ark-output.example.com/')) {
                return Http::response('seedance-video-bytes');
            }
            if ($request->method() === 'GET' && str_starts_with($request->url(), self::TASKS.'/')) {
                $state = count($this->taskStates) > 1 ? array_shift($this->taskStates) : ($this->taskStates[0] ?? ['id' => 'cgt-1', 'status' => 'queued']);

                return Http::response($state);
            }

            return Http::response(['error' => ['message' => 'unexpected']], 404);
        });
    }

    private function createCalls(): int
    {
        return count(Http::recorded(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === self::TASKS));
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
        $workspace = StoryWorkspace::query()->firstOrCreate(['project_id' => $project->id], StoryWorkspace::factory()->raw(['project_id' => $project->id]));
        $character = StoryCharacter::factory()->create(['story_workspace_id' => $workspace->id]);
        Storage::disk('images')->put($path, $bytes);

        return StoryCharacterReference::factory()->create([
            'story_character_id' => $character->id,
            'disk' => 'images',
            'path' => $path,
            'mime_type' => 'image/png',
        ]);
    }

    private function styleReference(Project $project, string $path, string $bytes): StoryStyleReference
    {
        $workspace = StoryWorkspace::query()->firstOrCreate(['project_id' => $project->id], StoryWorkspace::factory()->raw(['project_id' => $project->id]));
        $bible = StoryStyleBible::query()->firstOrCreate(['story_workspace_id' => $workspace->id], StoryStyleBible::factory()->raw(['story_workspace_id' => $workspace->id]));
        Storage::disk('images')->put($path, $bytes);

        return StoryStyleReference::factory()->create([
            'story_style_bible_id' => $bible->id,
            'version' => StoryStyleReference::query()->where('story_style_bible_id', $bible->id)->max('version') + 1,
            'disk' => 'images',
            'path' => $path,
            'mime_type' => 'image/png',
        ]);
    }
}
