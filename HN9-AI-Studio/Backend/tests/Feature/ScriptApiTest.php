<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ScriptStatus;
use App\Models\Project;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ScriptApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $project = Project::factory()->create();

        $this->getJson('/api/v1/projects/'.$project->uuid.'/scripts')->assertUnauthorized();
        $this->postJson('/api/v1/projects/'.$project->uuid.'/scripts', ['title' => 'X'])->assertUnauthorized();
    }

    public function test_owner_can_create_list_and_show_scripts(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts', [
                'title' => 'Launch VO',
                'body' => 'Hook. Problem. Offer.',
                'status' => ScriptStatus::Draft->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Launch VO')
            ->assertJsonPath('data.body', 'Hook. Problem. Offer.')
            ->assertJsonPath('data.project_id', $project->uuid);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/scripts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure(['data', 'meta']);

        $script = Script::query()->first();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/scripts/'.$script->uuid)
            ->assertOk()
            ->assertJsonPath('data.id', $script->uuid)
            ->assertJsonPath('data.title', 'Launch VO');
    }

    public function test_owner_can_update_and_delete_a_script(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $script = Script::factory()->for($project)->create([
            'title' => 'Original',
            'body' => 'Draft copy',
            'status' => ScriptStatus::Draft->value,
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid.'/scripts/'.$script->uuid, [
                'title' => 'Revised VO',
                'body' => 'Updated copy',
                'status' => ScriptStatus::Ready->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Revised VO')
            ->assertJsonPath('data.body', 'Updated copy')
            ->assertJsonPath('data.status', ScriptStatus::Ready->value);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/projects/'.$project->uuid.'/scripts/'.$script->uuid)
            ->assertNoContent();

        $this->assertSoftDeleted('scripts', ['id' => $script->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/scripts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_scripts_are_scoped_to_the_current_project(): void
    {
        $user = User::factory()->create();
        $projectA = Project::factory()->for($user)->create();
        $projectB = Project::factory()->for($user)->create();
        Script::factory()->for($projectA)->create(['title' => 'Alpha']);
        Script::factory()->for($projectB)->create(['title' => 'Beta']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$projectA->uuid.'/scripts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Alpha');
    }

    public function test_user_cannot_access_or_mutate_another_users_scripts(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $script = Script::factory()->for($project)->create(['title' => 'Owner script']);

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/scripts')
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts', ['title' => 'Hijack'])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/scripts/'.$script->uuid)
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid.'/scripts/'.$script->uuid, ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson('/api/v1/projects/'.$project->uuid.'/scripts/'.$script->uuid)
            ->assertForbidden();

        $this->assertDatabaseHas('scripts', [
            'id' => $script->id,
            'title' => 'Owner script',
            'deleted_at' => null,
        ]);
    }

    public function test_script_from_another_project_is_not_found(): void
    {
        $user = User::factory()->create();
        $projectA = Project::factory()->for($user)->create();
        $projectB = Project::factory()->for($user)->create();
        $script = Script::factory()->for($projectB)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$projectA->uuid.'/scripts/'.$script->uuid)
            ->assertNotFound();
    }

    public function test_validation_rejects_empty_title(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts', [
                'title' => '',
                'body' => 'Copy',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }
}
