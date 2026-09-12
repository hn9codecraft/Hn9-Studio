<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class IntegrationReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_store_does_not_pretend_a_job_was_created(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/exports', ['format' => 'csv'])
            ->assertStatus(501)
            ->assertJsonPath('error_code', 'not_implemented');
    }

    public function test_brand_brain_update_returns_public_project_uuid_not_integer_ids(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['name' => 'Original']);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/brand-brain?project='.$project->uuid, [
                'settings' => ['tone' => 'direct'],
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $project->uuid)
            ->assertJsonPath('data.name', 'Original');

        $this->assertDoesNotMatchRegularExpression('/"id"\s*:\s*\d+/', $response->getContent() ?: '');
        $this->assertArrayNotHasKey('user_id', $response->json('data'));
    }

    public function test_project_prompt_from_another_project_is_not_found(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $other = Project::factory()->for($user)->create();

        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$other->uuid.'/prompts', [
                'deliverable_type' => 'caption',
                'language' => 'en',
            ])
            ->assertCreated();

        $promptUuid = $created->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/prompts/'.$promptUuid)
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/projects/'.$project->uuid.'/prompts/'.$promptUuid)
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$other->uuid.'/prompts/'.$promptUuid)
            ->assertOk()
            ->assertJsonPath('data.id', $promptUuid);
    }
}
