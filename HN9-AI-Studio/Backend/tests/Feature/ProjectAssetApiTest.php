<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProjectAssetSource;
use App\Enums\ProjectAssetStatus;
use App\Enums\ProjectAssetType;
use App\Models\Project;
use App\Models\ProjectAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProjectAssetApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $project = Project::factory()->create();

        $this->getJson('/api/v1/projects/'.$project->uuid.'/assets')->assertUnauthorized();
        $this->postJson('/api/v1/projects/'.$project->uuid.'/assets', [
            'title' => 'X',
            'type' => ProjectAssetType::Image->value,
        ])->assertUnauthorized();
    }

    public function test_owner_can_create_list_and_show_assets(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/assets', [
                'title' => 'Brand kit cover',
                'type' => ProjectAssetType::Image->value,
                'source' => ProjectAssetSource::Manual->value,
                'status' => ProjectAssetStatus::Draft->value,
                'notes' => 'Hero still for the campaign.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Brand kit cover')
            ->assertJsonPath('data.type', ProjectAssetType::Image->value)
            ->assertJsonPath('data.source', ProjectAssetSource::Manual->value)
            ->assertJsonPath('data.status', ProjectAssetStatus::Draft->value)
            ->assertJsonPath('data.notes', 'Hero still for the campaign.')
            ->assertJsonPath('data.project_id', $project->uuid)
            ->assertJsonPath('data.file_url', null)
            ->assertJsonPath('data.mime_type', null);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/assets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure(['data', 'meta']);

        $asset = ProjectAsset::query()->first();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/assets/'.$asset->uuid)
            ->assertOk()
            ->assertJsonPath('data.id', $asset->uuid)
            ->assertJsonPath('data.title', 'Brand kit cover')
            ->assertJsonPath('data.file_url', null);
    }

    public function test_owner_can_update_and_delete_an_asset(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $asset = ProjectAsset::factory()->for($project)->create([
            'title' => 'Original',
            'type' => ProjectAssetType::Other->value,
            'status' => ProjectAssetStatus::Draft->value,
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid.'/assets/'.$asset->uuid, [
                'title' => 'Revised cover',
                'type' => ProjectAssetType::Document->value,
                'status' => ProjectAssetStatus::Ready->value,
                'source' => ProjectAssetSource::External->value,
                'file_url' => 'https://example.com/files/cover.pdf',
                'mime_type' => 'application/pdf',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Revised cover')
            ->assertJsonPath('data.type', ProjectAssetType::Document->value)
            ->assertJsonPath('data.status', ProjectAssetStatus::Ready->value)
            ->assertJsonPath('data.source', ProjectAssetSource::External->value)
            ->assertJsonPath('data.file_url', 'https://example.com/files/cover.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/projects/'.$project->uuid.'/assets/'.$asset->uuid)
            ->assertNoContent();

        $this->assertSoftDeleted('project_assets', ['id' => $asset->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/assets')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_assets_are_scoped_to_the_current_project(): void
    {
        $user = User::factory()->create();
        $projectA = Project::factory()->for($user)->create();
        $projectB = Project::factory()->for($user)->create();
        ProjectAsset::factory()->for($projectA)->create(['title' => 'Alpha']);
        ProjectAsset::factory()->for($projectB)->create(['title' => 'Beta']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$projectA->uuid.'/assets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Alpha');
    }

    public function test_user_cannot_access_or_mutate_another_users_assets(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $asset = ProjectAsset::factory()->for($project)->create(['title' => 'Owner asset']);

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/assets')
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/assets', [
                'title' => 'Hijack',
                'type' => ProjectAssetType::Image->value,
            ])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/assets/'.$asset->uuid)
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid.'/assets/'.$asset->uuid, ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson('/api/v1/projects/'.$project->uuid.'/assets/'.$asset->uuid)
            ->assertForbidden();

        $this->assertDatabaseHas('project_assets', [
            'id' => $asset->id,
            'title' => 'Owner asset',
            'deleted_at' => null,
        ]);
    }

    public function test_asset_from_another_project_is_not_found(): void
    {
        $user = User::factory()->create();
        $projectA = Project::factory()->for($user)->create();
        $projectB = Project::factory()->for($user)->create();
        $asset = ProjectAsset::factory()->for($projectB)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$projectA->uuid.'/assets/'.$asset->uuid)
            ->assertNotFound();
    }

    public function test_validation_rejects_empty_title_and_type(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/assets', [
                'title' => '',
                'type' => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'type']);
    }

    public function test_validation_rejects_generated_source_without_a_provider(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/assets', [
                'title' => 'Fake generated',
                'type' => ProjectAssetType::Image->value,
                'source' => ProjectAssetSource::Generated->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['source']);
    }

    public function test_validation_rejects_upload_source_and_invalid_file_url(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/assets', [
                'title' => 'Upload later',
                'type' => ProjectAssetType::Image->value,
                'source' => ProjectAssetSource::Upload->value,
                'file_url' => 'not-a-url',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['source', 'file_url']);
    }
}
