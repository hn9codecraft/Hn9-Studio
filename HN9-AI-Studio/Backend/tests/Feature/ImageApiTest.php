<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImageAspectRatio;
use App\Enums\ImageStatus;
use App\Models\Image;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ImageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $project = Project::factory()->create();

        $this->getJson('/api/v1/projects/'.$project->uuid.'/images')->assertUnauthorized();
        $this->postJson('/api/v1/projects/'.$project->uuid.'/images', [
            'title' => 'X',
            'prompt' => 'A still life',
        ])->assertUnauthorized();
    }

    public function test_owner_can_create_list_and_show_images(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images', [
                'title' => 'Hero still',
                'prompt' => 'Warm studio light, product on marble.',
                'negative_prompt' => 'blurry, text overlay',
                'aspect_ratio' => ImageAspectRatio::Landscape->value,
                'status' => ImageStatus::Draft->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Hero still')
            ->assertJsonPath('data.prompt', 'Warm studio light, product on marble.')
            ->assertJsonPath('data.negative_prompt', 'blurry, text overlay')
            ->assertJsonPath('data.aspect_ratio', ImageAspectRatio::Landscape->value)
            ->assertJsonPath('data.status', ImageStatus::Draft->value)
            ->assertJsonPath('data.project_id', $project->uuid)
            ->assertJsonPath('data.output_url', null)
            ->assertJsonPath('data.provider', null)
            ->assertJsonPath('data.provider_job_id', null);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/images')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure(['data', 'meta']);

        $image = Image::query()->first();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/images/'.$image->uuid)
            ->assertOk()
            ->assertJsonPath('data.id', $image->uuid)
            ->assertJsonPath('data.title', 'Hero still')
            ->assertJsonPath('data.output_url', null);
    }

    public function test_owner_can_update_and_delete_an_image(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $image = Image::factory()->for($project)->create([
            'title' => 'Original',
            'prompt' => 'Draft prompt',
            'status' => ImageStatus::Draft->value,
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid.'/images/'.$image->uuid, [
                'title' => 'Revised still',
                'prompt' => 'Updated prompt',
                'status' => ImageStatus::Pending->value,
                'aspect_ratio' => ImageAspectRatio::Portrait->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Revised still')
            ->assertJsonPath('data.prompt', 'Updated prompt')
            ->assertJsonPath('data.status', ImageStatus::Pending->value)
            ->assertJsonPath('data.aspect_ratio', ImageAspectRatio::Portrait->value)
            ->assertJsonPath('data.output_url', null);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/projects/'.$project->uuid.'/images/'.$image->uuid)
            ->assertNoContent();

        $this->assertSoftDeleted('images', ['id' => $image->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/images')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_images_are_scoped_to_the_current_project(): void
    {
        $user = User::factory()->create();
        $projectA = Project::factory()->for($user)->create();
        $projectB = Project::factory()->for($user)->create();
        Image::factory()->for($projectA)->create(['title' => 'Alpha']);
        Image::factory()->for($projectB)->create(['title' => 'Beta']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$projectA->uuid.'/images')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Alpha');
    }

    public function test_user_cannot_access_or_mutate_another_users_images(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $image = Image::factory()->for($project)->create(['title' => 'Owner image']);

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/images')
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images', [
                'title' => 'Hijack',
                'prompt' => 'Stolen prompt',
            ])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/images/'.$image->uuid)
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid.'/images/'.$image->uuid, ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson('/api/v1/projects/'.$project->uuid.'/images/'.$image->uuid)
            ->assertForbidden();

        $this->assertDatabaseHas('images', [
            'id' => $image->id,
            'title' => 'Owner image',
            'deleted_at' => null,
        ]);
    }

    public function test_image_from_another_project_is_not_found(): void
    {
        $user = User::factory()->create();
        $projectA = Project::factory()->for($user)->create();
        $projectB = Project::factory()->for($user)->create();
        $image = Image::factory()->for($projectB)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$projectA->uuid.'/images/'.$image->uuid)
            ->assertNotFound();
    }

    public function test_validation_rejects_empty_title_and_prompt(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images', [
                'title' => '',
                'prompt' => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'prompt']);
    }

    public function test_validation_rejects_completed_status_without_a_provider(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/images', [
                'title' => 'Fake complete',
                'prompt' => 'Should stay honest',
                'status' => ImageStatus::Completed->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }
}
