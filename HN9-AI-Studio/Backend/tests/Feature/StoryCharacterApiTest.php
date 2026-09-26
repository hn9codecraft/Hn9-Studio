<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Providers\AIServiceProvider;
use App\Story\Enums\StoryCharacterReferenceStatus;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithProviderPlatform;
use Tests\TestCase;

final class StoryCharacterApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProviderPlatform;

    public function test_unauthenticated_character_requests_are_rejected(): void
    {
        $uuid = (string) Str::uuid();
        $characterUuid = (string) Str::uuid();

        $this->getJson("/api/v1/story/projects/{$uuid}/characters")->assertUnauthorized();
        $this->postJson("/api/v1/story/projects/{$uuid}/characters", ['name' => 'Aarav'])->assertUnauthorized();
        $this->getJson("/api/v1/story/projects/{$uuid}/characters/{$characterUuid}")->assertUnauthorized();
        $this->patchJson("/api/v1/story/projects/{$uuid}/characters/{$characterUuid}", ['name' => 'A'])->assertUnauthorized();
        $this->deleteJson("/api/v1/story/projects/{$uuid}/characters/{$characterUuid}")->assertUnauthorized();
    }

    public function test_owner_can_create_read_update_and_archive_character(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $created = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters", [
                'name' => 'Aarav',
                'short_description' => 'Curious boy',
                'age' => '12',
                'gender_presentation' => 'boy',
                'appearance' => '12-year-old boy, black short hair, round face',
                'clothing' => 'Blue hoodie, black pants, white shoes',
                'personality' => 'Curious and brave',
                'special_details' => 'Small scar above left eyebrow',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Aarav')
            ->assertJsonPath('data.project.id', $project->uuid);

        $characterId = $created->json('data.id');
        $this->assertTrue(Str::isUuid($characterId));
        $this->assertDoesNotMatchRegularExpression('/"id"\s*:\s*\d+/', $created->getContent() ?: '');
        $this->assertSame(1, StoryWorkspace::query()->count());
        $this->assertSame(1, StoryCharacter::query()->count());

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/characters")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $characterId);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$project->uuid}/characters/{$characterId}", [
                'hair' => 'Black short hair',
                'face_description' => 'Round face, warm eyes',
            ])
            ->assertOk()
            ->assertJsonPath('data.hair', 'Black short hair')
            ->assertJsonPath('data.face_description', 'Round face, warm eyes');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/story/projects/{$project->uuid}/characters/{$characterId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/characters")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_character_validation_rejects_empty_name(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters", ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_non_owner_cannot_access_characters_idor(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $character = StoryCharacter::factory()->create([
            'story_workspace_id' => StoryWorkspace::factory()->create(['project_id' => $project->id])->id,
            'name' => 'Aarav',
        ]);

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/characters")
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}")
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}", [
                'name' => 'Hacked',
            ])
            ->assertForbidden();
    }

    public function test_upload_reference_creates_draft_version_with_private_storage(): void
    {
        Storage::fake('images');
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $character = $this->makeCharacter($user, $project);

        $file = UploadedFile::fake()->image('aarav.png', 64, 64);

        $response = $this->actingAs($user, 'sanctum')
            ->post("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/upload", [
                'file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.source', 'uploaded')
            ->assertJsonPath('data.is_approved_current', false);

        $payload = $response->json('data');
        $this->assertArrayNotHasKey('path', $payload);
        $this->assertArrayNotHasKey('disk', $payload);
        $this->assertStringNotContainsString('story-characters/', $response->getContent() ?: '');

        $reference = StoryCharacterReference::query()->where('uuid', $payload['id'])->firstOrFail();
        Storage::disk('images')->assertExists($reference->path);
        $this->assertSame('images', $reference->disk);
        $this->assertNotNull($reference->width);
        $this->assertNotNull($reference->height);
        $this->assertGreaterThan(0, $reference->size);
    }

    public function test_upload_rejects_non_image_file(): void
    {
        Storage::fake('images');
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $character = $this->makeCharacter($user, $project);

        $this->actingAs($user, 'sanctum')
            ->post("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/upload", [
                'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, StoryCharacterReference::query()->count());
    }

    public function test_reference_versioning_and_approval_lifecycle(): void
    {
        Storage::fake('images');
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $character = $this->makeCharacter($user, $project);

        $v1 = $this->actingAs($user, 'sanctum')
            ->post("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/upload", [
                'file' => UploadedFile::fake()->image('v1.png', 32, 32),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        $v2 = $this->actingAs($user, 'sanctum')
            ->post("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/upload", [
                'file' => UploadedFile::fake()->image('v2.png', 40, 40),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/{$v1}/submit-review")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_review');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/{$v1}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_approved_current', true);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/{$v2}/submit-review")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/{$v2}/approve")
            ->assertOk()
            ->assertJsonPath('data.is_approved_current', true);

        $character->refresh();
        $this->assertSame($v2, $character->approvedReference?->uuid);

        $old = StoryCharacterReference::query()->where('uuid', $v1)->firstOrFail();
        $this->assertSame(StoryCharacterReferenceStatus::Archived->value, $old->status);
        $this->assertSame(2, StoryCharacterReference::query()->count());

        $list = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $list);
    }

    public function test_reject_and_resubmit_reference(): void
    {
        Storage::fake('images');
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $character = $this->makeCharacter($user, $project);

        $id = $this->actingAs($user, 'sanctum')
            ->post("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/upload", [
                'file' => UploadedFile::fake()->image('ref.png', 24, 24),
            ], ['Accept' => 'application/json'])
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/{$id}/submit-review")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/{$id}/reject", [
                'comment' => 'Needs clearer face',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/{$id}/submit-review")
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
                'data' => [
                    ['b64_json' => $png],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $character = $this->makeCharacter($user, $project, [
            'name' => 'Aarav',
            'appearance' => '12-year-old boy, black short hair, round face',
            'clothing' => 'Blue hoodie, black pants, white shoes',
            'personality' => 'Curious and brave',
            'special_details' => 'Small scar above left eyebrow',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/generate")
            ->assertCreated()
            ->assertJsonPath('data.source', 'generated')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.provider', 'openai');

        $this->assertStringContainsString('Aarav', (string) $response->json('data.prompt'));
        $this->assertStringContainsString('Blue hoodie', (string) $response->json('data.prompt'));
        $this->assertArrayNotHasKey('path', $response->json('data'));
        $this->assertStringNotContainsString('sk-test', $response->getContent() ?: '');
        $this->assertStringNotContainsString('api_key', strtolower($response->getContent() ?: ''));

        $reference = StoryCharacterReference::query()->where('uuid', $response->json('data.id'))->firstOrFail();
        Storage::disk('images')->assertExists($reference->path);
        $this->assertSame('draft', $reference->status);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/images/generations');
        });
    }

    public function test_provider_failure_creates_no_reference_asset(): void
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
        $character = $this->makeCharacter($user, $project);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/generate")
            ->assertStatus(502);

        $this->assertSame(0, StoryCharacterReference::query()->count());
        $this->assertCount(0, Storage::disk('images')->allFiles());
    }

    public function test_non_owner_cannot_download_or_approve_reference(): void
    {
        Storage::fake('images');
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $character = $this->makeCharacter($owner, $project);

        $id = $this->actingAs($owner, 'sanctum')
            ->post("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/upload", [
                'file' => UploadedFile::fake()->image('ref.png', 20, 20),
            ], ['Accept' => 'application/json'])
            ->json('data.id');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/{$id}/submit-review")
            ->assertOk();

        $this->actingAs($intruder, 'sanctum')
            ->get("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/{$id}/file")
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/{$id}/approve")
            ->assertForbidden();
    }

    public function test_owner_can_download_reference_file(): void
    {
        Storage::fake('images');
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $character = $this->makeCharacter($user, $project);

        $id = $this->actingAs($user, 'sanctum')
            ->post("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/upload", [
                'file' => UploadedFile::fake()->image('ref.png', 28, 28),
            ], ['Accept' => 'application/json'])
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->get("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/{$id}/file")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_character_business_logic_does_not_hardcode_openai(): void
    {
        $path = base_path('app/Story/Services/StoryCharacterReferenceService.php');
        $source = (string) file_get_contents($path);

        $this->assertStringContainsString('ProviderDispatcherInterface', $source);
        $this->assertStringNotContainsString('OpenAI', $source);
        $this->assertStringNotContainsString('Gemini', $source);
        $this->assertStringNotContainsString('Seedance', $source);
        $this->assertStringNotContainsString('api.openai.com', $source);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeCharacter(User $user, Project $project, array $attributes = []): StoryCharacter
    {
        $workspace = StoryWorkspace::factory()->create(['project_id' => $project->id]);

        return StoryCharacter::factory()->create([
            'story_workspace_id' => $workspace->id,
            'name' => $attributes['name'] ?? 'Aarav',
            ...$attributes,
        ]);
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
