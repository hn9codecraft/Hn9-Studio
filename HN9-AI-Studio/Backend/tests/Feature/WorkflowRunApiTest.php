<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AgentExecution;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkflowRunApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_runs_from_projects_the_user_owns(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $mine = Project::factory()->for($user)->create();
        $theirs = Project::factory()->for($other)->create();

        WorkflowRun::factory()->count(2)->for($mine)->create();
        WorkflowRun::factory()->count(3)->for($theirs)->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workflow-runs')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta'])
            ->assertJsonCount(2, 'data');

        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_index_filters_and_paginates(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        WorkflowRun::factory()->for($project)->create(['status' => 'completed', 'workflow_key' => 'blog.pipeline']);
        WorkflowRun::factory()->for($project)->create(['status' => 'failed', 'workflow_key' => 'reel.pipeline']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workflow-runs?status=completed')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workflow-runs?workflow=reel.pipeline')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workflow-runs?per_page=1&page=2')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_show_returns_workflow_run(): void
    {
        $user = User::factory()->create();
        $run = WorkflowRun::factory()->for(Project::factory()->for($user))->create(['workflow_key' => 'blog.pipeline']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workflow-runs/'.$run->uuid)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $run->uuid)
            ->assertJsonPath('data.workflow_key', 'blog.pipeline');
    }

    public function test_timeline_and_logs_are_exposed(): void
    {
        $user = User::factory()->create();
        $run = WorkflowRun::factory()->for(Project::factory()->for($user))->create();
        AgentExecution::factory()->for($run)->create(['agent_key' => 'generation']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workflow-runs/'.$run->uuid.'/timeline')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.timeline');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workflow-runs/'.$run->uuid.'/logs')
            ->assertStatus(200);
    }

    public function test_retry_and_cancel_change_status(): void
    {
        $user = User::factory()->create();
        $run = WorkflowRun::factory()->for(Project::factory()->for($user))->create(['status' => 'failed']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/workflow-runs/'.$run->uuid.'/retry')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'queued');

        $run->refresh();
        $this->assertSame('queued', $run->status);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/workflow-runs/'.$run->uuid.'/cancel')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_invalid_uuid_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/workflow-runs/not-a-uuid')
            ->assertStatus(404);
    }

    public function test_a_user_cannot_view_another_users_workflow_run(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $run = WorkflowRun::factory()->for(Project::factory()->for($owner))->create();

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/workflow-runs/'.$run->uuid)
            ->assertStatus(403);
    }
}
