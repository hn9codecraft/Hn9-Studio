<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Storage\StorageInterface;
use App\Enums\ExecutionStatus;
use App\Enums\ImageReviewAction;
use App\Enums\ImageSource;
use App\Enums\ImageStatus;
use App\Enums\ProjectStatus;
use App\Models\Image;
use App\Models\MediaFile;
use App\Models\Project;
use App\Models\User;
use App\Providers\AIServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\InteractsWithProviderPlatform;
use Tests\TestCase;

final class ImageGenerationApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProviderPlatform;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_image_generation_requires_authentication(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active->value]);

        $this->postJson('/api/v1/projects/'.$project->uuid.'/images/generate', [
            'prompt' => 'A navy geometric mark',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('images', 0);
        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_user_cannot_generate_an_image_for_another_users_project(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($intruder, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/generate', [
                'prompt' => 'Stolen visual',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('images', 0);
    }

    public function test_missing_provider_credentials_create_no_image(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/generate', [
                'prompt' => 'A studio still',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'ai_provider_not_configured');

        $this->assertDatabaseCount('images', 0);
        $this->assertDatabaseCount('generated_assets', 0);
        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_unsupported_model_is_rejected_before_the_provider_is_called(): void
    {
        $this->bootOpenAiImageRuntime();
        Http::fake();

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/generate', [
                'prompt' => 'A studio still',
                'provider' => 'openai',
                'model' => 'configured-openai-model',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'image_generation_unsupported');

        Http::assertNothingSent();
        $this->assertDatabaseCount('images', 0);
    }

    public function test_successful_generation_stores_the_provider_image(): void
    {
        Storage::fake('images');
        $this->bootOpenAiImageRuntime();
        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response([
                'data' => [['b64_json' => self::PNG]],
            ]),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/generate', [
                'prompt' => 'Minimal navy geometric mark with an amber accent',
                'aspect_ratio' => '1:1',
                'provider' => 'openai',
                'model' => 'configured-image-model',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.image.source', ImageSource::Ai->value)
            ->assertJsonPath('data.image.status', ImageStatus::Draft->value)
            ->assertJsonPath('data.image.has_file', true)
            ->assertJsonPath('data.image.file.mime_type', 'image/png')
            ->assertJsonPath('data.image.provider', 'openai')
            ->assertJsonPath('data.image.output_url', null)
            ->assertJsonPath('data.dispatch.provider', 'openai');

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('sk-test-openai-key', $body);
        $this->assertStringNotContainsString(self::PNG, $body);

        $image = Image::query()->first();
        $this->assertNotNull($image);
        $this->assertNotNull($image->generated_asset_id);
        $this->assertSame(ImageStatus::Draft->value, $image->status);

        $file = MediaFile::query()->first();
        $this->assertNotNull($file);
        $this->assertSame('image/png', $file->mime_type);
        $this->assertSame('png', $file->extension);
        $this->assertGreaterThan(0, $file->size);
        $this->assertSame(1, $file->meta['width'] ?? null);
        $this->assertSame(1, $file->meta['height'] ?? null);
        Storage::disk('images')->assertExists($file->path);

        $this->actingAs($user, 'sanctum')
            ->get('/api/v1/projects/'.$project->uuid.'/images/'.$image->uuid.'/file')
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    public function test_temporary_provider_url_is_downloaded_and_not_stored_as_the_asset_url(): void
    {
        Storage::fake('images');
        $this->bootOpenAiImageRuntime();
        $png = base64_decode(self::PNG, true);
        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response([
                'data' => [['url' => 'https://cdn.example/temporary.png']],
            ]),
            'https://cdn.example/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/generate', [
                'prompt' => 'A downloaded still',
                'provider' => 'openai',
                'model' => 'configured-image-model',
            ])
            ->assertCreated()
            ->assertJsonPath('data.image.output_url', null)
            ->assertJsonPath('data.image.has_file', true);

        $this->assertStringNotContainsString('cdn.example', Image::query()->value('output_url') ?? '');
        $this->assertNotNull(MediaFile::query()->first());
    }

    public function test_provider_failure_creates_no_image_or_file(): void
    {
        $this->bootOpenAiImageRuntime();
        Sleep::fake();
        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response([
                'error' => ['message' => 'overloaded'],
            ], 503),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/generate', [
                'prompt' => 'A studio still',
                'provider' => 'openai',
                'model' => 'configured-image-model',
            ])
            ->assertStatus(502)
            ->assertJsonPath('error_code', 'ai_all_providers_failed');

        $this->assertDatabaseCount('images', 0);
        $this->assertDatabaseCount('media_files', 0);
        $this->assertDatabaseCount('generated_assets', 0);
    }

    public function test_provider_timeout_creates_no_image(): void
    {
        $this->bootOpenAiImageRuntime();
        Sleep::fake();
        Http::fake(function (): void {
            throw new ConnectionException('Connection timed out.');
        });

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/generate', [
                'prompt' => 'A studio still',
                'provider' => 'openai',
                'model' => 'configured-image-model',
            ]);

        $response->assertStatus(502);
        $this->assertStringNotContainsString('sk-test-openai-key', $response->getContent() ?: '');
        $this->assertDatabaseCount('images', 0);
        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_malformed_provider_response_creates_no_image(): void
    {
        $this->bootOpenAiImageRuntime();
        Sleep::fake();
        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response([
                'data' => [],
            ]),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/generate', [
                'prompt' => 'A studio still',
                'provider' => 'openai',
                'model' => 'configured-image-model',
            ])
            ->assertStatus(502);

        $this->assertDatabaseCount('images', 0);
        $this->assertDatabaseCount('media_files', 0);
        $this->assertDatabaseMissing('generated_assets', [
            'status' => ExecutionStatus::Completed->value,
            'type' => 'image',
        ]);
    }

    public function test_storage_failure_creates_no_studio_image(): void
    {
        $this->bootOpenAiImageRuntime();
        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response([
                'data' => [['b64_json' => self::PNG]],
            ]),
        ]);

        $this->mock(StorageInterface::class, function ($mock): void {
            $mock->shouldReceive('put')->once()->andThrow(new \RuntimeException('disk unavailable'));
        });

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/generate', [
                'prompt' => 'A studio still',
                'provider' => 'openai',
                'model' => 'configured-image-model',
            ])
            ->assertStatus(502)
            ->assertJsonPath('error_code', 'image_storage_failed');

        $this->assertDatabaseCount('images', 0);
        $this->assertDatabaseCount('media_files', 0);
        $this->assertDatabaseCount('generated_assets', 0);
    }

    public function test_user_cannot_read_another_users_generated_image_or_file(): void
    {
        Storage::fake('images');
        $this->bootOpenAiImageRuntime();
        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response([
                'data' => [['b64_json' => self::PNG]],
            ]),
        ]);

        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active->value]);

        $created = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/generate', [
                'prompt' => 'Private still',
                'provider' => 'openai',
                'model' => 'configured-image-model',
            ])
            ->assertCreated()
            ->json('data.image');

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/images/'.$created['id'])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->get('/api/v1/projects/'.$project->uuid.'/images/'.$created['id'].'/file')
            ->assertForbidden();

        $other = Project::factory()->for($intruder)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/projects/'.$other->uuid.'/images/'.$created['id'])
            ->assertNotFound();
    }

    public function test_archived_project_cannot_generate_an_image(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Archived->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/generate', [
                'prompt' => 'Should be refused',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'generation_project_not_editable');

        $this->assertDatabaseCount('images', 0);
    }

    public function test_generated_image_follows_the_review_workflow_and_regeneration_keeps_history(): void
    {
        Storage::fake('images');
        $this->bootOpenAiImageRuntime();
        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::sequence()
                ->push(['data' => [['b64_json' => self::PNG]]])
                ->push(['data' => [['b64_json' => self::PNG]]]),
        ]);

        $owner = User::factory()->reviewer()->create();
        $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active->value]);

        $first = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/generate', [
                'prompt' => 'Version one still',
                'provider' => 'openai',
                'model' => 'configured-image-model',
            ])
            ->assertCreated()
            ->json('data.image');

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/'.$first['id'].'/approve')
            ->assertStatus(409);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/'.$first['id'].'/submit-review')
            ->assertOk()
            ->assertJsonPath('data.status', ImageStatus::PendingReview->value);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/'.$first['id'].'/needs-rework', [
                'comment' => 'Use more amber and less clutter in the frame.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ImageStatus::NeedsRework->value)
            ->assertJsonPath('data.latest_rework.comment', 'Use more amber and less clutter in the frame.');

        $second = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/'.$first['id'].'/regenerate', [
                'prompt' => 'Version two still with amber',
                'provider' => 'openai',
                'model' => 'configured-image-model',
            ])
            ->assertCreated()
            ->json('data.image');

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame($first['id'], $second['parent_image_id']);
        $this->assertDatabaseHas('images', [
            'uuid' => $first['id'],
            'prompt' => 'Version one still',
            'status' => ImageStatus::NeedsRework->value,
        ]);
        $this->assertDatabaseCount('images', 2);
        $this->assertDatabaseCount('media_files', 2);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/'.$second['id'].'/submit-review')
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/'.$second['id'].'/approve', [
                'comment' => 'Approved for the reel.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ImageStatus::Approved->value)
            ->assertJsonPath('data.capabilities.edit', false);

        $this->actingAs($owner, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid.'/images/'.$second['id'], [
                'prompt' => 'Silent replacement',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'image_workflow_edit_locked');

        $this->assertDatabaseHas('images', [
            'uuid' => $second['id'],
            'prompt' => 'Version two still with amber',
            'status' => ImageStatus::Approved->value,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/images/'.$first['id'].'/review-history')
            ->assertOk()
            ->assertJsonFragment(['action' => ImageReviewAction::NeedsRework->value]);
    }

    public function test_owner_without_review_permission_cannot_approve(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active->value]);
        $image = Image::factory()->for($project)->create([
            'status' => ImageStatus::PendingReview->value,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/'.$image->uuid.'/approve')
            ->assertForbidden();

        $this->assertDatabaseHas('images', [
            'id' => $image->id,
            'status' => ImageStatus::PendingReview->value,
        ]);
    }

    public function test_needs_rework_requires_a_comment(): void
    {
        $owner = User::factory()->reviewer()->create();
        $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active->value]);
        $image = Image::factory()->for($project)->create([
            'status' => ImageStatus::PendingReview->value,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/'.$image->uuid.'/needs-rework', [
                'comment' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['comment']);
    }

    public function test_rate_limit_does_not_create_an_image(): void
    {
        $this->bootOpenAiImageRuntime();
        Sleep::fake();
        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response([
                'error' => ['message' => 'slow down'],
            ], 429),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images/generate', [
                'prompt' => 'A studio still',
                'provider' => 'openai',
                'model' => 'configured-image-model',
            ])
            ->assertStatus(502);

        $this->assertDatabaseCount('images', 0);
        $this->assertDatabaseCount('media_files', 0);
    }

    private function bootOpenAiImageRuntime(): void
    {
        config()->set('ai.providers.openai', [
            'enabled' => true,
            'api_key' => 'sk-test-openai-key',
            'base_url' => 'https://api.openai.com/v1',
            'default_model' => 'configured-openai-model',
            'models' => ['configured-openai-model'],
            'image_models' => ['configured-image-model'],
            'image_default_model' => 'configured-image-model',
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
