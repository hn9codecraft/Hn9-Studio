<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProjectAssetType;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProjectActivityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $project = Project::factory()->create();

        $this->getJson('/api/v1/projects/'.$project->uuid.'/activities')->assertUnauthorized();
    }

    public function test_factory_project_has_an_honest_empty_timeline(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/activities')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_real_project_create_appears_in_activity(): void
    {
        $user = User::factory()->create(['permissions' => ['project.create']]);

        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects', ['name' => 'Activity Demo'])
            ->assertCreated()
            ->json('data.id');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$created.'/activities')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'project.created')
            ->assertJsonPath('data.0.module', 'project')
            ->assertJsonPath('data.0.description', 'Project created')
            ->assertJsonPath('data.0.actor.name', $user->name)
            ->assertJsonPath('data.0.subject.title', 'Activity Demo');

        $item = $response->json('data.0');
        $this->assertIsString($item['id']);
        $this->assertArrayNotHasKey('ip_address', $item);
        $this->assertArrayNotHasKey('user_agent', $item);
        $this->assertArrayNotHasKey('properties', $item);
        $this->assertStringNotContainsString('203.0.113', $response->getContent() ?: '');
    }

    public function test_real_script_lifecycle_creates_activity_for_the_owning_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $scriptId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts', [
                'title' => 'Opening monologue',
                'body' => 'Draft body',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid.'/scripts/'.$scriptId, [
                'title' => 'Revised monologue',
            ])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/projects/'.$project->uuid.'/scripts/'.$scriptId)
            ->assertNoContent();

        $this->assertSoftDeleted('scripts', ['uuid' => $scriptId]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/activities')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $actions = collect($response->json('data'))->pluck('action')->all();
        $this->assertSame(['script.deleted', 'script.updated', 'script.created'], $actions);
        $this->assertSame('Revised monologue', $response->json('data.0.subject.title'));
        $this->assertSame($scriptId, $response->json('data.0.subject.id'));
    }

    public function test_real_image_video_and_asset_actions_are_recorded(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images', [
                'title' => 'Hero still',
                'prompt' => 'A quiet street at dusk',
            ])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos', [
                'title' => 'Teaser cut',
                'prompt' => 'Slow pan across the set',
            ])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/assets', [
                'title' => 'Brand kit cover',
                'type' => ProjectAssetType::Image->value,
            ])
            ->assertCreated();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/activities')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $actions = collect($response->json('data'))->pluck('action')->sort()->values()->all();
        $this->assertSame(['image.created', 'project_asset.created', 'video.created'], $actions);
    }

    public function test_other_user_cannot_view_project_activity(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts', [
                'title' => 'Owner script',
            ])
            ->assertCreated();

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/activities')
            ->assertForbidden();
    }

    public function test_activity_is_not_leaked_across_projects(): void
    {
        $user = User::factory()->create();
        $projectA = Project::factory()->for($user)->create();
        $projectB = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$projectA->uuid.'/scripts', [
                'title' => 'Only in A',
            ])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$projectA->uuid.'/activities')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject.title', 'Only in A');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$projectB->uuid.'/activities')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_pipeline_and_ip_fields_are_not_exposed_on_the_studio_timeline(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts', [
                'title' => 'Visible script',
            ])
            ->assertCreated();

        ActivityLog::factory()->create([
            'user_id' => $user->id,
            'subject_type' => $project->getMorphClass(),
            'subject_id' => $project->getKey(),
            'action' => 'workflow.created',
            'description' => 'Pipeline noise',
            'ip_address' => '203.0.113.7',
            'user_agent' => 'SecretAgent/1.0',
            'properties' => ['token' => 'should-not-leak'],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/activities')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'script.created');

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('203.0.113.7', $body);
        $this->assertStringNotContainsString('SecretAgent/1.0', $body);
        $this->assertStringNotContainsString('should-not-leak', $body);
        $this->assertStringNotContainsString('workflow.created', $body);
        $this->assertStringNotContainsString('Pipeline noise', $body);
    }

    public function test_module_filter_limits_results(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts', [
                'title' => 'A script',
            ])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images', [
                'title' => 'An image',
                'prompt' => 'prompt text',
            ])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/activities?module=script')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'script.created');
    }

    public function test_project_module_filter_does_not_include_asset_actions(): void
    {
        $user = User::factory()->create(['permissions' => ['project.create']]);

        $projectId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects', ['name' => 'Prefix check'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$projectId.'/assets', [
                'title' => 'Logo',
                'type' => ProjectAssetType::Image->value,
            ])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$projectId.'/activities')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.module', 'asset')
            ->assertJsonPath('data.0.action', 'project_asset.created')
            ->assertJsonPath('data.1.module', 'project');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$projectId.'/activities?module=project')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'project.created')
            ->assertJsonPath('data.0.module', 'project');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$projectId.'/activities?module=asset')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'project_asset.created')
            ->assertJsonPath('data.0.module', 'asset');
    }
}
