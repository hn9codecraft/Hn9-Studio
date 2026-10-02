<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StudioWorkflowsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_can_be_created_with_studio_workflows_alongside_other_settings(): void
    {
        $user = User::factory()->create(['permissions' => ['project.create']]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects', [
                'name' => 'Image Campaign',
                'settings' => ['studio_modules' => ['images', 'videos'], 'tone' => 'Warm'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.settings.studio_modules', ['images', 'videos'])
            ->assertJsonPath('data.settings.tone', 'Warm');

        $project = Project::query()->where('uuid', $response->json('data.id'))->firstOrFail();
        $this->assertSame(['images', 'videos'], $project->settings['studio_modules']);
        $this->assertSame('Warm', $project->settings['tone']);
    }

    public function test_project_can_be_created_without_any_studio_workflow(): void
    {
        $user = User::factory()->create(['permissions' => ['project.create']]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects', ['name' => 'Plain Project'])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/story/projects/'.$response->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.project.studio_modules', null);
    }

    public function test_unknown_or_duplicate_studio_workflows_are_rejected(): void
    {
        $user = User::factory()->create(['permissions' => ['project.create']]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects', [
                'name' => 'Bad Workflow',
                'settings' => ['studio_modules' => ['story', 'hologram']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['settings']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects', [
                'name' => 'Duplicate Workflow',
                'settings' => ['studio_modules' => ['story', 'story']],
            ])
            ->assertUnprocessable();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects', [
                'name' => 'String Workflow',
                'settings' => ['studio_modules' => 'story'],
            ])
            ->assertUnprocessable();

        $this->assertSame(0, Project::query()->count());
    }

    public function test_owner_can_change_studio_workflows_and_studio_workspace_reflects_them(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['settings' => ['brand_name' => 'HN9']]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid, [
                'settings' => ['brand_name' => 'HN9', 'studio_modules' => ['story', 'audio']],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.studio_modules', ['story', 'audio'])
            ->assertJsonPath('data.settings.brand_name', 'HN9');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/story/projects/'.$project->uuid)
            ->assertOk()
            ->assertJsonPath('data.project.studio_modules', ['story', 'audio'])
            ->assertJsonMissingPath('data.project.settings');
    }

    public function test_non_owner_cannot_change_another_users_studio_workflows(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create(['settings' => ['studio_modules' => ['story']]]);

        $this->actingAs($intruder, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid, [
                'settings' => ['studio_modules' => ['images']],
            ])
            ->assertForbidden();

        $this->assertSame(['story'], $project->fresh()->settings['studio_modules']);
    }
}
