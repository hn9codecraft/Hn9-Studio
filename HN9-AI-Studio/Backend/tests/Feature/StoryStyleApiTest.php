<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Providers\AIServiceProvider;
use App\Story\Enums\StoryStyleReferenceStatus;
use App\Story\Models\StoryStyleBible;
use App\Story\Models\StoryStyleReference;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithProviderPlatform;
use Tests\TestCase;

final class StoryStyleApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProviderPlatform;

    public function test_unauthenticated_style_requests_are_rejected(): void
    {
        $uuid = (string) Str::uuid();
        $ref = (string) Str::uuid();

        $this->getJson("/api/v1/story/projects/{$uuid}/style")->assertUnauthorized();
        $this->postJson("/api/v1/story/projects/{$uuid}/style")->assertUnauthorized();
        $this->patchJson("/api/v1/story/projects/{$uuid}/style", [])->assertUnauthorized();
        $this->getJson("/api/v1/story/projects/{$uuid}/style/references")->assertUnauthorized();
        $this->postJson("/api/v1/story/projects/{$uuid}/style/references/generate")->assertUnauthorized();
        $this->getJson("/api/v1/story/projects/{$uuid}/style/references/{$ref}")->assertUnauthorized();
    }

    public function test_owner_can_initialize_update_and_reread_style_bible(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $created = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/style")
            ->assertCreated()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.project.id', $project->uuid);

        $styleId = $created->json('data.id');
        $this->assertTrue(Str::isUuid($styleId));
        $this->assertSame(1, StoryStyleBible::query()->count());
        $this->assertSame(1, StoryWorkspace::query()->count());

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/style")
            ->assertOk()
            ->assertJsonPath('data.id', $styleId);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$project->uuid}/style", [
                'visual_style' => '  3D cinematic animation  ',
                'lighting' => 'Warm sunset lighting',
                'camera_style' => 'Slow cinematic camera movement',
                'environment_style' => 'Fantasy village',
                'mood' => 'Whimsical and emotional',
                'aspect_ratio' => '16:9',
            ])
            ->assertOk()
            ->assertJsonPath('data.visual_style', '3D cinematic animation')
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.aspect_ratio', '16:9');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/style")
            ->assertOk()
            ->assertJsonPath('data.mood', 'Whimsical and emotional');

        $this->assertDoesNotMatchRegularExpression('/"id"\s*:\s*\d+/', $created->getContent() ?: '');
    }

    public function test_style_bible_is_one_per_workspace_and_isolated(): void
    {
        $user = User::factory()->create();
        $a = Project::factory()->for($user)->create();
        $b = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$a->uuid}/style", ['mood' => 'Calm'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$b->uuid}/style", ['mood' => 'Intense'])
            ->assertOk();

        $this->assertSame(2, StoryStyleBible::query()->count());

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$a->uuid}/style")
            ->assertJsonPath('data.mood', 'Calm');
    }

    public function test_validation_rejects_invalid_aspect_ratio(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$project->uuid}/style", [
                'aspect_ratio' => '4:3',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['aspect_ratio']);
    }

    public function test_non_owner_cannot_access_style_idor(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/style")
            ->assertCreated();

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/style")
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$project->uuid}/style", ['mood' => 'Hacked'])
            ->assertForbidden();
    }

    public function test_upload_reference_creates_draft_version_with_private_storage(): void
    {
        Storage::fake('images');
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $response = $this->actingAs($user, 'sanctum')
            ->post("/api/v1/story/projects/{$project->uuid}/style/references/upload", [
                'file' => UploadedFile::fake()->image('style.png', 64, 64),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.source', 'uploaded')
            ->assertJsonPath('data.is_approved_current', false);

        $payload = $response->json('data');
        $this->assertArrayNotHasKey('path', $payload);
        $this->assertArrayNotHasKey('disk', $payload);
        $this->assertStringNotContainsString('story-styles/', $response->getContent() ?: '');

        $reference = StoryStyleReference::query()->where('uuid', $payload['id'])->firstOrFail();
        Storage::disk('images')->assertExists($reference->path);
        $this->assertSame(1, StoryStyleBible::query()->count());
    }

    public function test_upload_rejects_non_image_file(): void
    {
        Storage::fake('images');
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->post("/api/v1/story/projects/{$project->uuid}/style/references/upload", [
                'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, StoryStyleReference::query()->count());
    }

    public function test_reference_versioning_and_approval_lifecycle(): void
    {
        Storage::fake('images');
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $v1 = $this->actingAs($user, 'sanctum')
            ->post("/api/v1/story/projects/{$project->uuid}/style/references/upload", [
                'file' => UploadedFile::fake()->image('v1.png', 32, 32),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        $v2 = $this->actingAs($user, 'sanctum')
            ->post("/api/v1/story/projects/{$project->uuid}/style/references/upload", [
                'file' => UploadedFile::fake()->image('v2.png', 40, 40),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/style/references/{$v1}/submit-review")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_review');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/style/references/{$v1}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_approved_current', true);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/style/references/{$v2}/submit-review")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/style/references/{$v2}/approve")
            ->assertOk()
            ->assertJsonPath('data.is_approved_current', true);

        $style = StoryStyleBible::query()->firstOrFail();
        $this->assertSame($v2, $style->approvedReference?->uuid);

        $old = StoryStyleReference::query()->where('uuid', $v1)->firstOrFail();
        $this->assertSame(StoryStyleReferenceStatus::Archived->value, $old->status);
        $this->assertSame(2, StoryStyleReference::query()->count());
    }

    public function test_reject_and_resubmit_style_reference(): void
    {
        Storage::fake('images');
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $id = $this->actingAs($user, 'sanctum')
            ->post("/api/v1/story/projects/{$project->uuid}/style/references/upload", [
                'file' => UploadedFile::fake()->image('ref.png', 24, 24),
            ], ['Accept' => 'application/json'])
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/style/references/{$id}/submit-review")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/style/references/{$id}/reject", [
                'comment' => 'Needs warmer palette',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/style/references/{$id}/submit-review")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_review');
    }

    public function test_generated_reference_uses_provider_abstraction_and_stays_draft(): void
    {
        Storage::fake('images');
        $this->bootOpenAiImageRuntime();

        $png = base64_encode($this->tinyPng());
        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response([
                'data' => [['b64_json' => $png]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$project->uuid}/style", [
                'visual_style' => '3D cinematic animation',
                'lighting' => 'Warm sunset lighting',
                'camera_style' => 'Slow cinematic camera movement',
                'environment_style' => 'Fantasy village',
                'mood' => 'Whimsical and emotional',
            ])
            ->assertOk();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/style/references/generate")
            ->assertCreated()
            ->assertJsonPath('data.source', 'generated')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.provider', 'openai');

        $this->assertStringContainsString('3D cinematic animation', (string) $response->json('data.prompt'));
        $this->assertStringContainsString('Fantasy village', (string) $response->json('data.prompt'));
        $this->assertArrayNotHasKey('path', $response->json('data'));
        $this->assertStringNotContainsString('sk-test', $response->getContent() ?: '');

        $reference = StoryStyleReference::query()->where('uuid', $response->json('data.id'))->firstOrFail();
        Storage::disk('images')->assertExists($reference->path);
        $this->assertSame('draft', $reference->status);
    }

    public function test_provider_failure_creates_no_style_reference_asset(): void
    {
        Storage::fake('images');
        $this->bootOpenAiImageRuntime();

        Http::fake([
            'https://api.openai.com/v1/images/generations' => Http::response([
                'error' => ['message' => 'quota exceeded'],
            ], 429),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/style/references/generate")
            ->assertStatus(502);

        $this->assertSame(0, StoryStyleReference::query()->count());
        $this->assertCount(0, Storage::disk('images')->allFiles());
    }

    public function test_non_owner_cannot_download_or_approve_style_reference(): void
    {
        Storage::fake('images');
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $id = $this->actingAs($owner, 'sanctum')
            ->post("/api/v1/story/projects/{$project->uuid}/style/references/upload", [
                'file' => UploadedFile::fake()->image('ref.png', 20, 20),
            ], ['Accept' => 'application/json'])
            ->json('data.id');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/style/references/{$id}/submit-review")
            ->assertOk();

        $this->actingAs($intruder, 'sanctum')
            ->get("/api/v1/story/projects/{$project->uuid}/style/references/{$id}/file")
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/style/references/{$id}/approve")
            ->assertForbidden();
    }

    public function test_owner_can_download_style_reference_file(): void
    {
        Storage::fake('images');
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $id = $this->actingAs($user, 'sanctum')
            ->post("/api/v1/story/projects/{$project->uuid}/style/references/upload", [
                'file' => UploadedFile::fake()->image('ref.png', 28, 28),
            ], ['Accept' => 'application/json'])
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->get("/api/v1/story/projects/{$project->uuid}/style/references/{$id}/file")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_style_business_logic_does_not_hardcode_vendors(): void
    {
        $path = base_path('app/Story/Services/StoryStyleReferenceService.php');
        $source = (string) file_get_contents($path);

        $this->assertStringContainsString('ProviderDispatcherInterface', $source);
        $this->assertStringNotContainsString('OpenAI', $source);
        $this->assertStringNotContainsString('Gemini', $source);
        $this->assertStringNotContainsString('Seedance', $source);
        $this->assertStringNotContainsString('api.openai.com', $source);
    }

    public function test_m11_story_bible_and_character_routes_still_work(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/bible")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters", ['name' => 'Aarav'])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/story')
            ->assertOk()
            ->assertJsonPath('data.module', 'project_story');
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

    private function tinyPng(): string
    {
        $binary = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        $this->assertIsString($binary);

        return $binary;
    }
}
