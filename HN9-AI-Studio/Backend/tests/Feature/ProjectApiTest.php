<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProjectApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_project(): void
    {
        $user = User::factory()->create(['permissions' => ['project.create']]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects', [
                'name' => 'My Test Project',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'My Test Project');
    }

    public function test_list_and_show_project(): void
    {
        $user = User::factory()->create();

        Project::factory()->count(3)->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);

        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid)
            ->assertStatus(200)
            ->assertJsonPath('data.name', $project->name);
    }

    public function test_search_and_sort_projects(): void
    {
        $user = User::factory()->create(['permissions' => ['project.create']]);

        Project::factory()->for($user)->create(['name' => 'Alpha project']);
        Project::factory()->for($user)->create(['name' => 'Beta project']);
        Project::factory()->for($user)->create(['name' => 'Gamma project']);

        // Search for Beta
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects?search=Beta')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beta project');

        // Sort by name ascending
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects?sort=name&order=asc')
            ->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Alpha project');
    }

    public function test_update_and_archive_and_delete_restore(): void
    {
        $user = User::factory()->create();

        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid, ['name' => 'Updated'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/archive')
            ->assertStatus(200)
            ->assertJsonPath('data.status', ProjectStatus::Archived->value);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/projects/'.$project->uuid)
            ->assertStatus(204);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/restore')
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated');
    }

    public function test_create_and_list_inputs(): void
    {
        $user = User::factory()->create();

        $project = Project::factory()->for($user)->create();

        $project = Project::factory()->for($user)->create(['status' => 'active']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/inputs', [
                'deliverable_type' => 'blog_post',
                'language' => 'en',
                'payload' => ['title' => 'Hello'],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.deliverable_type', 'blog_post');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/inputs')
            ->assertStatus(200)
            ->assertJsonStructure(['data']);
    }
}
