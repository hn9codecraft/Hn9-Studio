<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GeneratedAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GeneratedAssetApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_assets_from_projects_the_user_owns(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $mine = Project::factory()->for($user)->create();
        $theirs = Project::factory()->for($other)->create();

        GeneratedAsset::factory()->count(2)->for($mine)->create();
        GeneratedAsset::factory()->count(3)->for($theirs)->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-assets')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta'])
            ->assertJsonCount(2, 'data');

        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_index_filters_by_project_provider_type_status_search_and_pagination(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        GeneratedAsset::factory()->for($project)->create([
            'type' => 'image',
            'provider' => 'openai',
            'status' => 'completed',
            'prompt' => 'Hero image for launch',
            'is_favorite' => true,
        ]);
        GeneratedAsset::factory()->for($project)->create([
            'type' => 'video',
            'provider' => 'claude',
            'status' => 'pending',
            'prompt' => 'Explainer video',
            'is_favorite' => false,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-assets?project='.$project->uuid)
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-assets?provider=openai')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'image');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-assets?type=image')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-assets?status=completed')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-assets?search=launch')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-assets?perPage=1&page=2')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_show_returns_asset(): void
    {
        $user = User::factory()->create();
        $asset = GeneratedAsset::factory()
            ->for(Project::factory()->for($user))
            ->create(['prompt' => 'Launch poster']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-assets/'.$asset->uuid)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $asset->uuid)
            ->assertJsonPath('data.prompt', 'Launch poster');
    }

    public function test_destroy_soft_deletes_asset(): void
    {
        $user = User::factory()->create();
        $asset = GeneratedAsset::factory()
            ->for(Project::factory()->for($user))
            ->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/generated-assets/'.$asset->uuid)
            ->assertStatus(204);

        $this->assertSoftDeleted('generated_assets', ['id' => $asset->id]);
    }

    public function test_update_allows_metadata_and_status(): void
    {
        $user = User::factory()->create();
        $asset = GeneratedAsset::factory()
            ->for(Project::factory()->for($user))
            ->create(['status' => 'pending', 'metadata' => ['foo' => 'bar']]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/generated-assets/'.$asset->uuid, [
                'status' => 'completed',
                'metadata' => ['foo' => 'baz'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.metadata.foo', 'baz');

        $this->assertDatabaseHas('generated_assets', [
            'id' => $asset->id,
            'status' => 'completed',
        ]);
    }

    public function test_cancel_sets_status_to_cancelled(): void
    {
        $user = User::factory()->create();
        $asset = GeneratedAsset::factory()
            ->for(Project::factory()->for($user))
            ->create(['status' => 'pending']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/generated-assets/'.$asset->uuid.'/cancel')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('generated_assets', [
            'id' => $asset->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_favorite_and_unfavorite_toggle_the_flag(): void
    {
        $user = User::factory()->create();
        $asset = GeneratedAsset::factory()
            ->for(Project::factory()->for($user))
            ->create(['is_favorite' => false]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/generated-assets/'.$asset->uuid.'/favorite')
            ->assertStatus(200)
            ->assertJsonPath('data.is_favorite', true);

        $this->assertDatabaseHas('generated_assets', ['id' => $asset->id, 'is_favorite' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/generated-assets/'.$asset->uuid.'/unfavorite')
            ->assertStatus(200)
            ->assertJsonPath('data.is_favorite', false);

        $this->assertDatabaseHas('generated_assets', ['id' => $asset->id, 'is_favorite' => false]);
    }

    public function test_a_user_cannot_view_another_users_asset(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $asset = GeneratedAsset::factory()
            ->for(Project::factory()->for($owner))
            ->create();

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/generated-assets/'.$asset->uuid)
            ->assertStatus(403);
    }
}
