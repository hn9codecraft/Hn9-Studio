<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\VideoAspectRatio;
use App\Enums\VideoDuration;
use App\Enums\VideoStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VideoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $project = Project::factory()->create();

        $this->getJson('/api/v1/projects/'.$project->uuid.'/videos')->assertUnauthorized();
        $this->postJson('/api/v1/projects/'.$project->uuid.'/videos', [
            'title' => 'X',
            'prompt' => 'A product reel',
        ])->assertUnauthorized();
    }

    public function test_owner_can_create_list_and_show_videos(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos', [
                'title' => 'Launch reel',
                'prompt' => 'Slow orbit around the product on marble.',
                'negative_prompt' => 'text overlay, shake',
                'aspect_ratio' => VideoAspectRatio::Portrait->value,
                'duration' => VideoDuration::Fifteen->value,
                'status' => VideoStatus::Draft->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Launch reel')
            ->assertJsonPath('data.prompt', 'Slow orbit around the product on marble.')
            ->assertJsonPath('data.negative_prompt', 'text overlay, shake')
            ->assertJsonPath('data.aspect_ratio', VideoAspectRatio::Portrait->value)
            ->assertJsonPath('data.duration', VideoDuration::Fifteen->value)
            ->assertJsonPath('data.status', VideoStatus::Draft->value)
            ->assertJsonPath('data.project_id', $project->uuid)
            ->assertJsonPath('data.output_url', null)
            ->assertJsonPath('data.provider', null)
            ->assertJsonPath('data.provider_job_id', null);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/videos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure(['data', 'meta']);

        $video = Video::query()->first();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/videos/'.$video->uuid)
            ->assertOk()
            ->assertJsonPath('data.id', $video->uuid)
            ->assertJsonPath('data.title', 'Launch reel')
            ->assertJsonPath('data.output_url', null);
    }

    public function test_owner_can_update_and_delete_a_video(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $video = Video::factory()->for($project)->create([
            'title' => 'Original',
            'prompt' => 'Draft prompt',
            'status' => VideoStatus::Draft->value,
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid.'/videos/'.$video->uuid, [
                'title' => 'Revised reel',
                'prompt' => 'Updated prompt',
                'status' => VideoStatus::Pending->value,
                'aspect_ratio' => VideoAspectRatio::Square->value,
                'duration' => VideoDuration::Thirty->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Revised reel')
            ->assertJsonPath('data.prompt', 'Updated prompt')
            ->assertJsonPath('data.status', VideoStatus::Pending->value)
            ->assertJsonPath('data.aspect_ratio', VideoAspectRatio::Square->value)
            ->assertJsonPath('data.duration', VideoDuration::Thirty->value)
            ->assertJsonPath('data.output_url', null);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/projects/'.$project->uuid.'/videos/'.$video->uuid)
            ->assertNoContent();

        $this->assertSoftDeleted('videos', ['id' => $video->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/videos')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_videos_are_scoped_to_the_current_project(): void
    {
        $user = User::factory()->create();
        $projectA = Project::factory()->for($user)->create();
        $projectB = Project::factory()->for($user)->create();
        Video::factory()->for($projectA)->create(['title' => 'Alpha']);
        Video::factory()->for($projectB)->create(['title' => 'Beta']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$projectA->uuid.'/videos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Alpha');
    }

    public function test_user_cannot_access_or_mutate_another_users_videos(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $video = Video::factory()->for($project)->create(['title' => 'Owner video']);

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/videos')
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos', [
                'title' => 'Hijack',
                'prompt' => 'Stolen prompt',
            ])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/videos/'.$video->uuid)
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid.'/videos/'.$video->uuid, ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson('/api/v1/projects/'.$project->uuid.'/videos/'.$video->uuid)
            ->assertForbidden();

        $this->assertDatabaseHas('videos', [
            'id' => $video->id,
            'title' => 'Owner video',
            'deleted_at' => null,
        ]);
    }

    public function test_video_from_another_project_is_not_found(): void
    {
        $user = User::factory()->create();
        $projectA = Project::factory()->for($user)->create();
        $projectB = Project::factory()->for($user)->create();
        $video = Video::factory()->for($projectB)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$projectA->uuid.'/videos/'.$video->uuid)
            ->assertNotFound();
    }

    public function test_validation_rejects_empty_title_and_prompt(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos', [
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
            ->postJson('/api/v1/projects/'.$project->uuid.'/videos', [
                'title' => 'Fake complete',
                'prompt' => 'Should stay honest',
                'status' => VideoStatus::Completed->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }
}
