<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Storage\StorageInterface;
use App\Enums\ProjectStatus;
use App\Enums\VideoReviewAction;
use App\Enums\VideoSource;
use App\Enums\VideoStatus;
use App\Models\Image;
use App\Models\MediaFile;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use App\Providers\AIServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\InteractsWithProviderPlatform;
use Tests\TestCase;

final class VideoGenerationApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProviderPlatform;

    private const OPERATION = 'operations/job-1';

    private const DOWNLOAD = 'https://generativelanguage.googleapis.com/v1beta/files/vid:download';

    public function test_video_generation_requires_authentication(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active->value]);

        $this->postJson('/api/v1/projects/'.$project->uuid.'/videos/generate', [
            'prompt' => 'A slow orbit around the product',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('videos', 0);
        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_user_cannot_generate_a_video_for_another_users_project(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($intruder, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/generate', [
                'prompt' => 'Stolen reel',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('videos', 0);
    }

    public function test_missing_provider_credentials_create_no_video(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/generate', [
                'prompt' => 'A studio reel',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'ai_provider_not_configured');

        $this->assertDatabaseCount('videos', 0);
        $this->assertDatabaseCount('generated_assets', 0);
        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_unsupported_provider_is_rejected(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/generate', [
                'prompt' => 'A studio reel',
                'provider' => 'openai',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['provider']);

        $this->assertDatabaseCount('videos', 0);
    }

    public function test_unsupported_model_is_rejected_before_the_provider_is_called(): void
    {
        $this->bootGeminiVideoRuntime();
        Http::fake();

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/generate', [
                'prompt' => 'A studio reel',
                'provider' => 'gemini',
                'model' => 'configured-text-model',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'video_generation_unsupported');

        Http::assertNothingSent();
        $this->assertDatabaseCount('videos', 0);
    }

    public function test_generation_starts_processing_and_completes_only_after_provider_done(): void
    {
        Storage::fake('videos');
        $this->bootGeminiVideoRuntime();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/configured-video-model:predictLongRunning' => Http::response([
                'name' => self::OPERATION,
            ]),
            'https://generativelanguage.googleapis.com/v1beta/operations/job-1' => Http::sequence()
                ->push(['name' => self::OPERATION, 'done' => false])
                ->push($this->completedPayload()),
            self::DOWNLOAD.'*' => Http::response($this->videoBytes(), 200, ['Content-Type' => 'video/mp4']),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/generate', [
                'prompt' => 'Slow orbit around the product on marble',
                'aspect_ratio' => '16:9',
                'provider' => 'gemini',
                'model' => 'configured-video-model',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.video.source', VideoSource::Ai->value)
            ->assertJsonPath('data.video.status', VideoStatus::Processing->value)
            ->assertJsonPath('data.video.has_file', false)
            ->assertJsonPath('data.video.output_url', null)
            ->assertJsonPath('data.video.provider_job_id', null)
            ->assertJsonPath('data.video.provider', 'gemini')
            ->assertJsonPath('data.dispatch.accepted', true)
            ->assertJsonPath('data.dispatch.done', false);

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('gemini-test-key', $body);
        $this->assertStringNotContainsString(self::OPERATION, $body);
        $this->assertStringNotContainsString(self::DOWNLOAD, $body);

        $video = Video::query()->first();
        $this->assertNotNull($video);
        $this->assertSame(self::OPERATION, $video->provider_job_id);
        $this->assertSame(VideoStatus::Processing->value, $video->status);
        $this->assertDatabaseCount('media_files', 0);

        $this->travel(6)->seconds();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/videos/'.$video->uuid.'/status')
            ->assertOk()
            ->assertJsonPath('data.status', VideoStatus::Completed->value)
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.file.mime_type', 'video/mp4')
            ->assertJsonPath('data.output_url', null)
            ->assertJsonPath('data.provider_job_id', null);

        $file = MediaFile::query()->first();
        $this->assertNotNull($file);
        $this->assertSame('video/mp4', $file->mime_type);
        $this->assertSame('mp4', $file->extension);
        $this->assertGreaterThan(0, $file->size);
        Storage::disk('videos')->assertExists($file->path);

        $this->actingAs($user, 'sanctum')
            ->get('/api/v1/projects/'.$project->uuid.'/videos/'.$video->uuid.'/file')
            ->assertOk()
            ->assertHeader('content-type', 'video/mp4');
    }

    public function test_provider_failure_after_accept_marks_the_existing_video_failed(): void
    {
        $this->bootGeminiVideoRuntime();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/configured-video-model:predictLongRunning' => Http::response([
                'name' => self::OPERATION,
            ]),
            'https://generativelanguage.googleapis.com/v1beta/operations/job-1' => Http::response([
                'name' => self::OPERATION,
                'done' => true,
                'error' => ['message' => 'safety filter'],
            ]),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/generate', [
                'prompt' => 'A studio reel',
                'provider' => 'gemini',
                'model' => 'configured-video-model',
            ])
            ->assertCreated();

        $this->travel(6)->seconds();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/videos/'.Video::query()->value('uuid').'/status')
            ->assertOk()
            ->assertJsonPath('data.status', VideoStatus::Failed->value)
            ->assertJsonPath('data.has_file', false);

        $this->assertDatabaseCount('media_files', 0);
        $this->assertSame('safety filter', Video::query()->first()?->generation['error'] ?? null);
    }

    public function test_start_failure_creates_no_video(): void
    {
        $this->bootGeminiVideoRuntime();
        Sleep::fake();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/configured-video-model:predictLongRunning' => Http::response([
                'error' => ['message' => 'overloaded'],
            ], 503),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/generate', [
                'prompt' => 'A studio reel',
                'provider' => 'gemini',
                'model' => 'configured-video-model',
            ])
            ->assertStatus(502);

        $this->assertDatabaseCount('videos', 0);
        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_provider_timeout_on_start_creates_no_video(): void
    {
        $this->bootGeminiVideoRuntime();
        Sleep::fake();
        Http::fake(function (): void {
            throw new ConnectionException('Connection timed out.');
        });

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/generate', [
                'prompt' => 'A studio reel',
                'provider' => 'gemini',
                'model' => 'configured-video-model',
            ]);

        $response->assertStatus(502);
        $this->assertStringNotContainsString('gemini-test-key', $response->getContent() ?: '');
        $this->assertDatabaseCount('videos', 0);
    }

    public function test_storage_failure_after_completion_marks_the_video_failed(): void
    {
        $this->bootGeminiVideoRuntime();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/configured-video-model:predictLongRunning' => Http::response([
                'name' => self::OPERATION,
            ]),
            'https://generativelanguage.googleapis.com/v1beta/operations/job-1' => Http::response($this->completedPayload()),
            self::DOWNLOAD.'*' => Http::response($this->videoBytes(), 200, ['Content-Type' => 'video/mp4']),
        ]);

        $this->mock(StorageInterface::class, function ($mock): void {
            $mock->shouldReceive('put')->once()->andThrow(new \RuntimeException('disk unavailable'));
        });

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/generate', [
                'prompt' => 'A studio reel',
                'provider' => 'gemini',
                'model' => 'configured-video-model',
            ])
            ->assertCreated();

        $this->travel(6)->seconds();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/videos/'.Video::query()->value('uuid').'/status')
            ->assertOk()
            ->assertJsonPath('data.status', VideoStatus::Failed->value);

        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_timeout_marks_processing_video_failed(): void
    {
        $this->bootGeminiVideoRuntime();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/configured-video-model:predictLongRunning' => Http::response([
                'name' => self::OPERATION,
            ]),
            'https://generativelanguage.googleapis.com/v1beta/operations/job-1' => Http::response([
                'name' => self::OPERATION,
                'done' => false,
            ]),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/generate', [
                'prompt' => 'A studio reel',
                'provider' => 'gemini',
                'model' => 'configured-video-model',
            ])
            ->assertCreated()
            ->assertJsonPath('data.video.status', VideoStatus::Processing->value);

        $this->travel(901)->seconds();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/videos/'.Video::query()->value('uuid').'/status')
            ->assertOk()
            ->assertJsonPath('data.status', VideoStatus::Failed->value);

        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_user_cannot_read_another_users_generated_video_or_status(): void
    {
        Storage::fake('videos');
        $this->bootGeminiVideoRuntime();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/configured-video-model:predictLongRunning' => Http::response([
                'name' => self::OPERATION,
            ]),
            'https://generativelanguage.googleapis.com/v1beta/operations/job-1' => Http::response($this->completedPayload()),
            self::DOWNLOAD.'*' => Http::response($this->videoBytes(), 200, ['Content-Type' => 'video/mp4']),
        ]);

        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active->value]);

        $created = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/generate', [
                'prompt' => 'Private reel',
                'provider' => 'gemini',
                'model' => 'configured-video-model',
            ])
            ->assertCreated()
            ->json('data.video');

        $this->travel(6)->seconds();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/videos/'.$created['id'].'/status')
            ->assertOk()
            ->assertJsonPath('data.status', VideoStatus::Completed->value);

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/videos/'.$created['id'])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/videos/'.$created['id'].'/status')
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->get('/api/v1/projects/'.$project->uuid.'/videos/'.$created['id'].'/file')
            ->assertForbidden();

        $other = Project::factory()->for($intruder)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/projects/'.$other->uuid.'/videos/'.$created['id'])
            ->assertNotFound();
    }

    public function test_image_to_video_rejects_another_projects_image(): void
    {
        $this->bootGeminiVideoRuntime();
        Http::fake();

        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active->value]);
        $other = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active->value]);
        $image = Image::factory()->for($other)->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/generate', [
                'prompt' => 'Animate this still',
                'image_id' => $image->uuid,
                'provider' => 'gemini',
                'model' => 'configured-video-model',
            ])
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('videos', 0);
    }

    public function test_image_to_video_uses_a_project_owned_stored_image(): void
    {
        Storage::fake('images');
        Storage::fake('videos');
        $this->bootGeminiVideoRuntime();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/configured-video-model:predictLongRunning' => Http::response([
                'name' => self::OPERATION,
            ]),
            'https://generativelanguage.googleapis.com/v1beta/operations/job-1' => Http::response(['name' => self::OPERATION, 'done' => false]),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);
        $image = Image::factory()->for($project)->create();
        $path = $project->uuid.'/source.png';
        Storage::disk('images')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
        $file = new MediaFile([
            'disk' => 'images',
            'path' => $path,
            'original_name' => 'source.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => 70,
            'collection' => 'images',
        ]);
        $file->mediable()->associate($image);
        $file->save();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/generate', [
                'prompt' => 'Animate this still',
                'image_id' => $image->uuid,
                'provider' => 'gemini',
                'model' => 'configured-video-model',
            ])
            ->assertCreated()
            ->assertJsonPath('data.video.image_id', $image->uuid)
            ->assertJsonPath('data.video.generation.mode', 'image_to_video')
            ->assertJsonPath('data.video.status', VideoStatus::Processing->value);
    }

    public function test_archived_project_cannot_generate_a_video(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Archived->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/generate', [
                'prompt' => 'Should be refused',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'video_generation_project_not_editable');

        $this->assertDatabaseCount('videos', 0);
    }

    public function test_generated_video_follows_the_review_workflow_and_regeneration_keeps_history(): void
    {
        Storage::fake('videos');
        $this->bootGeminiVideoRuntime();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/configured-video-model:predictLongRunning' => Http::response([
                'name' => self::OPERATION,
            ]),
            'https://generativelanguage.googleapis.com/v1beta/operations/*' => Http::response($this->completedPayload()),
            self::DOWNLOAD.'*' => Http::response($this->videoBytes(), 200, ['Content-Type' => 'video/mp4']),
        ]);

        $owner = User::factory()->reviewer()->create();
        $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active->value]);

        $first = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/generate', [
                'prompt' => 'Version one reel',
                'provider' => 'gemini',
                'model' => 'configured-video-model',
            ])
            ->assertCreated()
            ->json('data.video');

        $this->travel(6)->seconds();

        $first = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/videos/'.$first['id'].'/status')
            ->assertOk()
            ->assertJsonPath('data.status', VideoStatus::Completed->value)
            ->json('data');

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/'.$first['id'].'/approve')
            ->assertStatus(409);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/'.$first['id'].'/submit-review')
            ->assertOk()
            ->assertJsonPath('data.status', VideoStatus::PendingReview->value);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/'.$first['id'].'/needs-rework', [
                'comment' => 'Hold the product longer and reduce camera shake.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', VideoStatus::NeedsRework->value)
            ->assertJsonPath('data.latest_rework.comment', 'Hold the product longer and reduce camera shake.');

        $this->travel(6)->seconds();

        $second = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/'.$first['id'].'/regenerate', [
                'prompt' => 'Version two reel with a longer hold',
                'provider' => 'gemini',
                'model' => 'configured-video-model',
            ])
            ->assertCreated()
            ->json('data.video');

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame($first['id'], $second['parent_video_id']);
        $this->assertDatabaseHas('videos', [
            'uuid' => $first['id'],
            'prompt' => 'Version one reel',
            'status' => VideoStatus::NeedsRework->value,
        ]);

        $this->travel(6)->seconds();

        $second = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/videos/'.$second['id'].'/status')
            ->assertOk()
            ->json('data');

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/'.$second['id'].'/submit-review')
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/'.$second['id'].'/approve', [
                'comment' => 'Approved for the launch.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', VideoStatus::Approved->value)
            ->assertJsonPath('data.capabilities.edit', false);

        $this->actingAs($owner, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid.'/videos/'.$second['id'], [
                'prompt' => 'Silent replacement',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'video_workflow_edit_locked');

        $this->assertDatabaseHas('videos', [
            'uuid' => $second['id'],
            'prompt' => 'Version two reel with a longer hold',
            'status' => VideoStatus::Approved->value,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/videos/'.$first['id'].'/review-history')
            ->assertOk()
            ->assertJsonFragment(['action' => VideoReviewAction::NeedsRework->value]);
    }

    public function test_owner_without_review_permission_cannot_approve(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active->value]);
        $video = Video::factory()->for($project)->create([
            'status' => VideoStatus::PendingReview->value,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/'.$video->uuid.'/approve')
            ->assertForbidden();

        $this->assertDatabaseHas('videos', [
            'id' => $video->id,
            'status' => VideoStatus::PendingReview->value,
        ]);
    }

    public function test_needs_rework_requires_a_comment(): void
    {
        $owner = User::factory()->reviewer()->create();
        $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active->value]);
        $video = Video::factory()->for($project)->create([
            'status' => VideoStatus::PendingReview->value,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/'.$video->uuid.'/needs-rework', [
                'comment' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['comment']);
    }

    public function test_client_cannot_supply_a_provider_job_id(): void
    {
        $this->bootGeminiVideoRuntime();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/configured-video-model:predictLongRunning' => Http::response([
                'name' => self::OPERATION,
            ]),
            'https://generativelanguage.googleapis.com/v1beta/operations/job-1' => Http::response([
                'name' => self::OPERATION,
                'done' => false,
            ]),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos/generate', [
                'prompt' => 'A studio reel',
                'provider' => 'gemini',
                'model' => 'configured-video-model',
                'provider_job_id' => 'operations/forged',
            ])
            ->assertCreated();

        $this->assertSame(self::OPERATION, Video::query()->value('provider_job_id'));
        $this->assertNotSame('operations/forged', Video::query()->value('provider_job_id'));
    }

    /**
     * @return array<string, mixed>
     */
    private function completedPayload(): array
    {
        return [
            'name' => self::OPERATION,
            'done' => true,
            'response' => [
                'generateVideoResponse' => [
                    'generatedSamples' => [
                        ['video' => ['uri' => self::DOWNLOAD]],
                    ],
                ],
            ],
        ];
    }

    private function videoBytes(): string
    {
        return 'ftypisom'.str_repeat('0', 64);
    }

    private function bootGeminiVideoRuntime(): void
    {
        config()->set('ai.providers.gemini', [
            'enabled' => true,
            'api_key' => 'gemini-test-key',
            'base_url' => 'https://generativelanguage.googleapis.com',
            'version' => 'v1beta',
            'default_model' => 'configured-text-model',
            'models' => ['configured-text-model'],
            'image_models' => [],
            'video_models' => ['configured-video-model'],
            'video_default_model' => 'configured-video-model',
            'priority' => 100,
            'timeout' => 5,
            'max_retries' => 0,
        ]);

        (new AIServiceProvider($this->app))->boot();

        $this->configurePlatform([
            'ai.routing.strategy' => 'priority',
            'ai.retry.jitter' => false,
            'ai.retry.delay_ms' => 1,
            'ai.retry.max_attempts' => 1,
        ]);
    }
}
