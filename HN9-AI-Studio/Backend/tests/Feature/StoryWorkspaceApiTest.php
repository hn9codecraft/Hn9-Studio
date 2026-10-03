<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StoryWorkspaceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_story_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/story')->assertUnauthorized();
        $this->getJson('/api/v1/story/projects')->assertUnauthorized();
        $this->getJson('/api/v1/story/capabilities')->assertUnauthorized();
        $this->getJson('/api/v1/story/projects/'.Str::uuid())->assertUnauthorized();
    }

    public function test_entry_returns_module_metadata_and_capability_catalog(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/story')
            ->assertOk()
            ->assertJsonPath('data.module', 'project_story')
            ->assertJsonPath('data.title', 'Creative Production Studio');

        $capabilities = $response->json('data.capabilities');
        $this->assertIsArray($capabilities);
        $this->assertCount(6, $capabilities);
        $this->assertSame('text_to_video', $capabilities[0]['capability']);
        $this->assertTrue($capabilities[0]['available']);
        $this->assertNotEmpty($capabilities[0]['adapters']);
        $this->assertStringNotContainsString('seedance', strtolower($response->getContent() ?: ''));
        $this->assertStringNotContainsString('higgsfield', strtolower($response->getContent() ?: ''));
        $this->assertStringNotContainsString('runway', strtolower($response->getContent() ?: ''));
        $this->assertStringNotContainsString('kling', strtolower($response->getContent() ?: ''));
        $this->assertStringNotContainsString('veo', strtolower($response->getContent() ?: ''));
        foreach ($capabilities[0]['adapters'] as $adapter) {
            $this->assertStringStartsWith('catalog.', (string) $adapter);
        }
    }

    public function test_owner_sees_only_their_projects_for_selection(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $owned = Project::factory()->for($owner)->create(['name' => 'Owner Reel']);
        Project::factory()->for($other)->create(['name' => 'Foreign Reel']);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/story/projects')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Owner Reel');

        $this->assertTrue(Str::isUuid($response->json('data.0.id')));
        $this->assertSame($owned->uuid, $response->json('data.0.id'));
        $this->assertDoesNotMatchRegularExpression('/"id"\s*:\s*\d+/', $response->getContent() ?: '');
    }

    public function test_owner_can_open_and_reuse_a_unique_workspace(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $first = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/story/projects/'.$project->uuid)
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.project.id', $project->uuid)
            ->assertJsonPath('data.project.name', $project->name);

        $this->assertTrue(Str::isUuid($first->json('data.id')));
        $this->assertSame(1, StoryWorkspace::query()->count());

        $second = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/story/projects/'.$project->uuid)
            ->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, StoryWorkspace::query()->count());
        $this->assertDoesNotMatchRegularExpression('/"id"\s*:\s*\d+/', $first->getContent() ?: '');
        $this->assertStringNotContainsString('disk', $first->getContent() ?: '');
        $this->assertStringNotContainsString('api_key', $first->getContent() ?: '');
        $this->assertStringNotContainsString('path', $first->getContent() ?: '');
    }

    public function test_non_owner_cannot_open_another_users_story(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/story/projects/'.$project->uuid)
            ->assertForbidden();

        $this->assertSame(0, StoryWorkspace::query()->count());
    }

    public function test_unknown_project_uuid_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/story/projects/'.Str::uuid())
            ->assertNotFound();
    }

    public function test_numeric_project_id_is_rejected(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/story/projects/'.$project->getKey())
            ->assertNotFound();
    }

    public function test_capability_catalog_does_not_call_providers(): void
    {
        Http::fake();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/story/capabilities')
            ->assertOk()
            ->assertJsonCount(6, 'data');

        Http::assertNothingSent();
    }

    public function test_m10_project_routes_remain_available(): void
    {
        $user = User::factory()->create(['permissions' => ['project.create']]);
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid)
            ->assertOk()
            ->assertJsonPath('data.id', $project->uuid);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/scripts')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/images')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/videos')
            ->assertOk();
    }
}
