<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryStyleBible;
use App\Story\Models\StoryStyleReference;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StoryVideoGenerationApiTest extends TestCase
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

    public function test_text_to_video_submits_once_and_hides_secrets(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'name' => 'models/configured-video-model/operations/story-op-1',
            ]),
        ]);

        [$user, $project] = $this->ownerProject();
        $key = 'live-'.Str::uuid();

        $first = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'text_to_video',
                'duration_seconds' => 30,
                'aspect_ratio' => '9:16',
                'prompt' => 'A continuous story scene',
                'idempotency_key' => $key,
            ])
            ->assertCreated()
            ->assertJsonPath('data.output_url', null)
            ->assertJsonPath('data.job.provider', 'video.live')
            ->assertJsonPath('data.job.operation_id', 'models/configured-video-model/operations/story-op-1');

        $this->assertSame([8, 8, 8, 8], $first->json('data.units'));
        $body = strtolower($first->getContent() ?: '');
        $this->assertStringNotContainsString('test-key', $body);
        $this->assertStringNotContainsString('key=', $body);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'text_to_video',
                'duration_seconds' => 30,
                'aspect_ratio' => '9:16',
                'prompt' => 'duplicate',
                'idempotency_key' => $key,
            ])
            ->assertOk()
            ->assertJsonPath('data.created', false);

        Http::assertSentCount(1);
        $this->assertSame(4, StoryVideoGenerationJob::query()->count());
    }

    public function test_non_owner_cannot_generate(): void
    {
        Http::fake();
        [, $project] = $this->ownerProject();
        $intruder = User::factory()->create();

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'text_to_video',
                'duration_seconds' => 8,
                'aspect_ratio' => '9:16',
                'prompt' => 'no',
            ])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_anonymous_cannot_generate_or_read_a_job(): void
    {
        Http::fake();
        [, $project] = $this->ownerProject();

        $this->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
            'capability' => 'text_to_video',
            'duration_seconds' => 8,
            'prompt' => 'private',
        ])->assertUnauthorized();

        $this->getJson("/api/v1/story/projects/{$project->uuid}/video/jobs/".Str::uuid())
            ->assertUnauthorized();

        $this->getJson("/api/v1/story/projects/{$project->uuid}/video/jobs/".Str::uuid().'/file')
            ->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_image_to_video_submits_an_owned_character_image(): void
    {
        Storage::fake('images');
        $this->fakeProviderStart('story-op-image');
        [$user, $project] = $this->ownerProject();
        $reference = $this->characterReference($project, 'owned/character.png', 'owned-image-bytes');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'image_to_video',
                'duration_seconds' => 8,
                'aspect_ratio' => '9:16',
                'prompt' => 'Animate the character',
                'inputs' => [[
                    'type' => 'image',
                    'asset_id' => $reference->uuid,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.job.provider', 'video.live')
            ->assertJsonPath('data.output_url', null);

        $encoded = base64_encode('owned-image-bytes');
        Http::assertSent(function ($request) use ($encoded): bool {
            return str_contains($request->url(), 'predictLongRunning')
                && str_contains($request->body(), $encoded);
        });
        $this->assertStringNotContainsString('owned/character.png', $response->getContent() ?: '');
    }

    public function test_image_to_video_rejects_another_projects_reference_and_invalid_ids(): void
    {
        Storage::fake('images');
        Http::fake();
        [$user, $project] = $this->ownerProject();
        $foreign = $this->characterReference(Project::factory()->create(), 'foreign/character.png', 'foreign-bytes');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'image_to_video',
                'duration_seconds' => 8,
                'prompt' => 'no',
                'inputs' => [[
                    'type' => 'image',
                    'asset_id' => $foreign->uuid,
                ]],
            ])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'image_to_video',
                'duration_seconds' => 8,
                'prompt' => 'no',
                'inputs' => [[
                    'type' => 'image',
                    'asset_id' => '../secret.png',
                ]],
            ])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'image_to_video',
                'duration_seconds' => 8,
                'prompt' => 'no',
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
    }

    public function test_reference_to_video_accepts_an_owned_style_reference(): void
    {
        Storage::fake('images');
        $this->fakeProviderStart('story-op-reference');
        [$user, $project] = $this->ownerProject();
        $reference = $this->styleReference($project, 'owned/style.png', 'style-image-bytes');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'reference_to_video',
                'duration_seconds' => 8,
                'aspect_ratio' => '16:9',
                'prompt' => 'Match the style',
                'inputs' => [[
                    'type' => 'reference_image',
                    'asset_id' => $reference->uuid,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.job.capability', 'reference_to_video')
            ->assertJsonPath('data.output_url', null);

        $encoded = base64_encode('style-image-bytes');
        Http::assertSent(fn ($request): bool => str_contains($request->body(), $encoded));
    }

    public function test_reference_to_video_rejects_a_missing_or_foreign_reference(): void
    {
        Storage::fake('images');
        Http::fake();
        [$user, $project] = $this->ownerProject();
        $foreign = $this->styleReference(Project::factory()->create(), 'foreign/style.png', 'foreign-style');
        $missing = $this->styleReference($project, 'missing/style.png', 'never-written');
        Storage::disk('images')->delete('missing/style.png');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'reference_to_video',
                'duration_seconds' => 8,
                'prompt' => 'no',
                'inputs' => [[
                    'type' => 'reference_image',
                    'asset_id' => $foreign->uuid,
                ]],
            ])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'reference_to_video',
                'duration_seconds' => 8,
                'prompt' => 'no',
                'inputs' => [[
                    'type' => 'reference_image',
                    'asset_id' => $missing->uuid,
                ]],
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_unsupported_capability_does_not_call_a_provider(): void
    {
        Http::fake();
        [$user, $project] = $this->ownerProject();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'audio',
                'duration_seconds' => 8,
                'prompt' => 'not this sprint',
            ])
            ->assertStatus(501);

        Http::assertNothingSent();
        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
    }

    public function test_video_edit_without_a_stored_source_does_not_call_a_provider(): void
    {
        Http::fake();
        [$user, $project] = $this->ownerProject();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'video_edit',
                'duration_seconds' => 8,
                'prompt' => 'edit later',
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
    }

    public function test_failed_provider_stores_no_placeholder(): void
    {
        Storage::fake('videos');
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'upstream failed key=test-key sk-live-secret'],
            ], 500),
        ]);
        [$user, $project] = $this->ownerProject();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'text_to_video',
                'duration_seconds' => 8,
                'prompt' => 'fail',
            ])
            ->assertStatus(422);

        $body = strtolower($response->getContent() ?: '');
        $this->assertStringNotContainsString('test-key', $body);
        $this->assertStringNotContainsString('sk-live-secret', $body);
        $this->assertSame([], Storage::disk('videos')->allFiles());
        $job = StoryVideoGenerationJob::query()->first();
        $this->assertNotNull($job);
        $this->assertSame('failed', $job->status);
        $this->assertNull($job->provider_metadata['storage'] ?? null);
    }

    public function test_owner_can_store_and_read_a_private_video_file(): void
    {
        Storage::fake('videos');
        $this->fakeProviderLifecycle('story-op-file', 'https://generativelanguage.googleapis.com/download/story-video');
        [$user, $project] = $this->ownerProject();

        $created = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'text_to_video',
                'duration_seconds' => 8,
                'prompt' => 'store me',
            ])
            ->assertCreated();

        $jobId = $created->json('data.job.id');

        $status = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/video/jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('data.output_url', null)
            ->assertJsonPath('data.job.status', 'completed');

        $this->assertStringNotContainsString('test-key', strtolower($status->getContent() ?: ''));
        $this->assertNotEmpty(Storage::disk('videos')->allFiles());

        $this->actingAs($user, 'sanctum')
            ->get("/api/v1/story/projects/{$project->uuid}/video/jobs/{$jobId}/file")
            ->assertOk();
    }

    public function test_non_owner_cannot_read_job_status_or_file(): void
    {
        Storage::fake('videos');
        $this->fakeProviderStart('story-op-idor');
        [$user, $project] = $this->ownerProject();
        $intruder = User::factory()->create();

        $jobId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'text_to_video',
                'duration_seconds' => 8,
                'prompt' => 'private file',
            ])
            ->assertCreated()
            ->json('data.job.id');

        $job = StoryVideoGenerationJob::query()->where('uuid', $jobId)->firstOrFail();
        Storage::disk('videos')->put($project->uuid.'/private.mp4', 'private-video');
        $job->forceFill([
            'status' => 'completed',
            'provider_metadata' => [
                'storage' => [
                    'disk' => 'videos',
                    'path' => $project->uuid.'/private.mp4',
                    'mime' => 'video/mp4',
                ],
            ],
        ])->save();

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/video/jobs/{$jobId}")
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->get("/api/v1/story/projects/{$project->uuid}/video/jobs/{$jobId}/file")
            ->assertForbidden();
    }

    public function test_download_failure_and_keyed_uri_store_no_file(): void
    {
        Storage::fake('videos');
        $this->fakeProviderLifecycle(
            'story-op-bad-download',
            'https://generativelanguage.googleapis.com/download/story-video',
            downloadStatus: 500,
        );
        [$user, $project] = $this->ownerProject();
        $jobId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'text_to_video',
                'duration_seconds' => 8,
                'prompt' => 'download fails',
            ])
            ->assertCreated()
            ->json('data.job.id');

        $failed = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/video/jobs/{$jobId}")
            ->assertStatus(422);

        $this->assertStringNotContainsString('test-key', strtolower($failed->getContent() ?: ''));
        $this->assertSame([], Storage::disk('videos')->allFiles());
        $this->assertSame('failed', StoryVideoGenerationJob::query()->where('uuid', $jobId)->value('status'));
    }

    public function test_keyed_download_uri_is_rejected_without_storing_a_file(): void
    {
        Storage::fake('videos');
        $this->fakeProviderLifecycle(
            'story-op-keyed',
            'https://generativelanguage.googleapis.com/download/story-video?key=secret-download-key',
        );
        [$user, $project] = $this->ownerProject();
        $keyedId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/video/generate", [
                'capability' => 'text_to_video',
                'duration_seconds' => 8,
                'prompt' => 'keyed uri',
                'idempotency_key' => 'keyed-once',
            ])
            ->assertCreated()
            ->json('data.job.id');

        $keyed = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/video/jobs/{$keyedId}")
            ->assertOk()
            ->assertJsonPath('data.job.status', 'failed');

        $this->assertStringNotContainsString('secret-download-key', $keyed->getContent() ?: '');
        $this->assertSame([], Storage::disk('videos')->allFiles());
    }

    /**
     * @return array{0: User, 1: Project}
     */
    private function ownerProject(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        return [$user, $project];
    }

    private function fakeProviderStart(string $operation): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'name' => 'models/configured-video-model/operations/'.$operation,
            ]),
        ]);
    }

    private function fakeProviderLifecycle(string $operation, string $uri, int $downloadStatus = 200): void
    {
        $name = 'models/configured-video-model/operations/'.$operation;
        Http::fake(function ($request) use ($name, $uri, $downloadStatus) {
            $url = $request->url();
            if (str_contains($url, '/download/')) {
                return Http::response(
                    $downloadStatus === 200 ? 'fake-mp4-bytes' : ['error' => ['message' => 'download failed key=test-key']],
                    $downloadStatus,
                );
            }
            if (str_contains($url, ':predictLongRunning')) {
                return Http::response(['name' => $name]);
            }

            return Http::response([
                'done' => true,
                'response' => [
                    'generateVideoResponse' => [
                        'generatedSamples' => [
                            ['video' => ['uri' => $uri]],
                        ],
                    ],
                ],
            ]);
        });
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

    private function styleReference(Project $project, string $path, string $bytes): StoryStyleReference
    {
        $workspace = StoryWorkspace::factory()->create(['project_id' => $project->id]);
        $bible = StoryStyleBible::factory()->create(['story_workspace_id' => $workspace->id]);
        Storage::disk('images')->put($path, $bytes);

        return StoryStyleReference::factory()->create([
            'story_style_bible_id' => $bible->id,
            'disk' => 'images',
            'path' => $path,
            'mime_type' => 'image/png',
        ]);
    }
}
