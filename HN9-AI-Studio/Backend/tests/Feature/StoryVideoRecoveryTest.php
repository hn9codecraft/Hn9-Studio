<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessStoryVideoJob;
use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Services\StoryVideoJobRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class StoryVideoRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION = 'models/configured-video-model/operations/story-op-recover';

    private const DOWNLOAD = 'https://generativelanguage.googleapis.com/download/story-video';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'story_video.real_provider.enabled' => true,
            'story_video.real_provider.durations' => [8],
            'story_video.timeouts.max_attempts' => 3,
            'ai.providers.gemini.enabled' => true,
            'ai.providers.gemini.api_key' => 'test-key',
            'ai.providers.gemini.max_retries' => 0,
            'ai.providers.gemini.video_models' => ['configured-video-model'],
            'ai.providers.gemini.video_default_model' => 'configured-video-model',
        ]);

        Http::preventStrayRequests();
    }

    public function test_duplicate_submit_returns_the_original_job_in_sync_mode(): void
    {
        $this->fakeProvider(['pending']);
        [$user, $project] = $this->ownerProject();

        $first = $this->generate($user, $project, 'sync-once')->assertCreated();
        $second = $this->generate($user, $project, 'sync-once')
            ->assertOk()
            ->assertJsonPath('data.created', false);

        $this->assertSame($first->json('data.job.id'), $second->json('data.job.id'));
        $this->assertSame(1, $this->submitCount());
        $this->assertSame(1, StoryVideoGenerationJob::query()->count());
    }

    public function test_queued_mode_submits_once_on_the_queue_and_honours_idempotency(): void
    {
        config(['story_video.queue.enabled' => true]);
        Queue::fake();
        $this->fakeProvider(['pending']);
        [$user, $project] = $this->ownerProject();

        $jobId = $this->generate($user, $project, 'queued-once')
            ->assertCreated()
            ->assertJsonPath('data.job.status', 'queued')
            ->assertJsonPath('data.job.operation_id', null)
            ->json('data.job.id');
        $this->generate($user, $project, 'queued-once')
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.job.id', $jobId);

        Queue::assertPushed(ProcessStoryVideoJob::class, 1);
        Http::assertNothingSent();

        $job = $this->job($jobId);
        $this->runQueued($job);
        $this->runQueued($job);

        $job->refresh();
        $this->assertSame(self::OPERATION, $job->operation_id);
        $this->assertSame('processing', $job->status);
        $this->assertNotNull($job->started_at);
        $this->assertSame(1, $this->submitCount());
    }

    public function test_recovery_continues_the_stored_operation_without_a_second_submit(): void
    {
        Storage::fake('videos');
        $this->fakeProvider(['pending', 'done', 'done', 'done']);
        [$user, $project] = $this->ownerProject();
        $jobId = $this->generate($user, $project, 'recover-me')->assertCreated()->json('data.job.id');

        $this->artisan('story:recover-video-jobs')->assertSuccessful();
        $this->assertSame('processing', $this->job($jobId)->status);

        $this->artisan('story:recover-video-jobs')->assertSuccessful();
        $job = $this->job($jobId);
        $this->assertSame('completed', $job->status);
        $this->assertSame(self::OPERATION, $job->operation_id);
        $files = Storage::disk('videos')->allFiles();
        $this->assertCount(1, $files);

        $this->artisan('story:recover-video-jobs')->assertSuccessful();
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/video/jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('data.job.status', 'completed')
            ->assertJsonPath('data.job.recovering', false)
            ->assertJsonPath('data.output_url', null);

        $this->assertSame($files, Storage::disk('videos')->allFiles());
        $this->assertSame(1, $this->submitCount());
        foreach ($this->operationPolls() as $url) {
            $this->assertStringContainsString('operations/story-op-recover', $url);
        }
    }

    public function test_claimed_submit_without_an_operation_is_failed_and_never_resent(): void
    {
        $this->fakeProvider(['pending']);
        [, $project] = $this->ownerProject();
        $job = $this->jobFor($project, [
            'status' => 'queued',
            'operation_id' => null,
            'started_at' => now()->subMinutes(10),
        ]);

        $this->runQueued($job);
        $this->artisan('story:recover-video-jobs')->assertSuccessful();

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame('SUBMISSION_UNCONFIRMED', $job->error_code);
        $this->assertNull($job->operation_id);
        Http::assertNothingSent();
    }

    public function test_retryable_failure_counts_attempts_on_the_same_operation_then_stops(): void
    {
        Log::spy();
        $this->fakeProvider(['unavailable', 'unavailable', 'unavailable']);
        [$user, $project] = $this->ownerProject();
        $jobId = $this->generate($user, $project, 'flaky')->assertCreated()->json('data.job.id');

        $first = $this->jobStatus($user, $project, $jobId)
            ->assertOk()
            ->assertJsonPath('data.job.status', 'submitted')
            ->assertJsonPath('data.job.retry_count', 1)
            ->assertJsonPath('data.job.recovering', true)
            ->assertJsonPath('data.job.failed_checks', 1)
            ->assertJsonPath('data.job.max_attempts', 3)
            ->assertJsonPath('data.job.error_code', 'UPSTREAM_ERROR')
            ->assertJsonPath('data.job.operation_id', self::OPERATION);
        $this->assertNoSecrets($first->getContent() ?: '');

        $this->jobStatus($user, $project, $jobId)
            ->assertOk()
            ->assertJsonPath('data.job.retry_count', 2)
            ->assertJsonPath('data.job.operation_id', self::OPERATION);

        $last = $this->jobStatus($user, $project, $jobId)
            ->assertOk()
            ->assertJsonPath('data.job.status', 'failed')
            ->assertJsonPath('data.job.retry_count', 3)
            ->assertJsonPath('data.job.recovering', false)
            ->assertJsonPath('data.job.operation_id', self::OPERATION);
        $this->assertStringContainsString('Stopped after 3 attempts', (string) $last->json('data.job.error_message'));
        $this->assertNoSecrets($last->getContent() ?: '');

        $this->jobStatus($user, $project, $jobId)->assertOk()->assertJsonPath('data.job.status', 'failed');

        $this->assertSame(1, $this->submitCount());
        $this->assertCount(3, $this->operationPolls());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => ! str_contains(strtolower((string) json_encode($context)), 'test-key')
                && ! str_contains((string) json_encode($context), 'https://'))
            ->times(3);
    }

    public function test_retryable_failure_recovers_and_completes_without_resubmitting(): void
    {
        Storage::fake('videos');
        $this->fakeProvider(['unavailable', 'done', 'done']);
        [$user, $project] = $this->ownerProject();
        $jobId = $this->generate($user, $project, 'blip')->assertCreated()->json('data.job.id');

        $this->jobStatus($user, $project, $jobId)->assertJsonPath('data.job.recovering', true);
        $this->jobStatus($user, $project, $jobId)
            ->assertOk()
            ->assertJsonPath('data.job.status', 'completed')
            ->assertJsonPath('data.job.retry_count', 1)
            ->assertJsonPath('data.job.recovering', false)
            ->assertJsonPath('data.job.failed_checks', 0)
            ->assertJsonPath('data.job.operation_id', self::OPERATION);

        $this->assertCount(1, Storage::disk('videos')->allFiles());
        $this->assertSame(1, $this->submitCount());
    }

    public function test_non_retryable_failure_stays_failed(): void
    {
        $this->fakeProvider(['not_found', 'pending']);
        [$user, $project] = $this->ownerProject();
        $jobId = $this->generate($user, $project, 'gone')->assertCreated()->json('data.job.id');

        $this->jobStatus($user, $project, $jobId)
            ->assertOk()
            ->assertJsonPath('data.job.status', 'failed')
            ->assertJsonPath('data.job.error_code', 'INVALID_PROVIDER_RESPONSE')
            ->assertJsonPath('data.job.retry_count', 1);

        $this->jobStatus($user, $project, $jobId)->assertOk()->assertJsonPath('data.job.status', 'failed');
        $this->artisan('story:recover-video-jobs')->assertSuccessful();
        $this->runQueued($this->job($jobId));

        $this->assertSame('failed', $this->job($jobId)->status);
        $this->assertCount(1, $this->operationPolls());
        $this->assertSame(1, $this->submitCount());
    }

    public function test_provider_reported_failure_is_not_retried(): void
    {
        $this->fakeProvider(['error', 'pending']);
        [$user, $project] = $this->ownerProject();
        $jobId = $this->generate($user, $project, 'rejected')->assertCreated()->json('data.job.id');

        $this->jobStatus($user, $project, $jobId)->assertOk()->assertJsonPath('data.job.status', 'failed');
        $this->jobStatus($user, $project, $jobId)->assertOk()->assertJsonPath('data.job.status', 'failed');

        $this->assertSame(0, $this->job($jobId)->retry_count);
        $this->assertCount(1, $this->operationPolls());
        $this->assertSame(1, $this->submitCount());
    }

    public function test_other_users_cannot_read_or_advance_a_job(): void
    {
        $this->fakeProvider(['unavailable']);
        [$user, $project] = $this->ownerProject();
        $jobId = $this->generate($user, $project, 'private')->assertCreated()->json('data.job.id');
        $intruder = User::factory()->create();
        $intruderProject = Project::factory()->for($intruder)->create();

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/video/jobs/{$jobId}")
            ->assertForbidden();
        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/v1/story/projects/{$intruderProject->uuid}/video/jobs/{$jobId}")
            ->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/story/projects/{$project->uuid}/video/jobs/{$jobId}")
            ->assertUnauthorized();

        $job = $this->job($jobId);
        $this->assertSame(0, $job->retry_count);
        $this->assertSame('submitted', $job->status);
        $this->assertCount(0, $this->operationPolls());
    }

    /**
     * @param  list<string>  $polls  pending|done|unavailable|not_found|error per operation poll
     */
    private function fakeProvider(array $polls): void
    {
        Http::fake(function (Request $request) use (&$polls) {
            $url = $request->url();
            if (str_contains($url, '/download/')) {
                return Http::response('fake-mp4-bytes');
            }
            if (str_contains($url, ':predictLongRunning')) {
                return Http::response(['name' => self::OPERATION]);
            }

            $next = array_shift($polls) ?? 'pending';

            return match ($next) {
                'done' => Http::response(['done' => true, 'response' => ['generateVideoResponse' => [
                    'generatedSamples' => [['video' => ['uri' => self::DOWNLOAD]]],
                ]]]),
                'unavailable' => Http::response(['error' => ['message' => 'backend unavailable key=test-key at '.self::DOWNLOAD.'?key=test-key']], 503),
                'not_found' => Http::response(['error' => ['message' => 'operation not found']], 404),
                'error' => Http::response(['done' => true, 'error' => ['message' => 'request rejected by provider']]),
                default => Http::response(['done' => false]),
            };
        });
    }

    private function generate(User $user, Project $project, string $key): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'text_to_video',
                'duration_seconds' => 8,
                'prompt' => 'A recoverable scene',
                'idempotency_key' => $key,
            ]);
    }

    private function jobStatus(User $user, Project $project, string $jobId): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/video/jobs/{$jobId}");
    }

    private function runQueued(StoryVideoGenerationJob $job): void
    {
        (new ProcessStoryVideoJob($job->id))->handle(app(StoryVideoJobRunner::class));
    }

    private function job(string $uuid): StoryVideoGenerationJob
    {
        return StoryVideoGenerationJob::query()->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function jobFor(Project $project, array $attributes): StoryVideoGenerationJob
    {
        $workspace = \App\Story\Models\StoryWorkspace::factory()->create(['project_id' => $project->id]);

        return StoryVideoGenerationJob::factory()->create(array_merge([
            'story_workspace_id' => $workspace->id,
            'capability' => 'text_to_video',
            'provider_key' => 'video.live',
            'request_payload' => ['capability' => 'text_to_video', 'prompt' => 'claimed', 'duration_seconds' => 8],
        ], $attributes));
    }

    private function submitCount(): int
    {
        return Http::recorded(static fn (Request $request): bool => str_contains($request->url(), ':predictLongRunning'))->count();
    }

    /**
     * @return list<string>
     */
    private function operationPolls(): array
    {
        return Http::recorded(static fn (Request $request): bool => str_contains($request->url(), '/operations/'))
            ->map(static fn (array $pair): string => $pair[0]->url())
            ->values()
            ->all();
    }

    private function assertNoSecrets(string $body): void
    {
        $this->assertStringNotContainsString('test-key', strtolower($body));
        $this->assertStringNotContainsString('key=', $body);
        $this->assertStringNotContainsString('/download/', $body);
    }

    /**
     * @return array{0: User, 1: Project}
     */
    private function ownerProject(): array
    {
        $user = User::factory()->create();

        return [$user, Project::factory()->for($user)->create()];
    }
}
