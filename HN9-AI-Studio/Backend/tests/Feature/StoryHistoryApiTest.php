<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryUsageLedgerEntry;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class StoryHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public function test_a_terminal_job_is_recorded_once_and_a_missing_cost_stays_null(): void
    {
        [$owner, $project, $workspace] = $this->project();
        $job = StoryVideoGenerationJob::factory()->create([
            'story_workspace_id' => $workspace->id,
            'operation_id' => 'operations/story-history-1',
            'status' => 'queued',
        ]);
        $job->forceFill(['status' => 'processing', 'started_at' => now()])->save();
        $this->assertSame(0, StoryUsageLedgerEntry::query()->count());

        $job->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'provider_metadata' => [
                'storage' => ['disk' => 'videos', 'path' => 'story/private-output.mp4'],
            ],
        ])->save();
        $job->forceFill(['retry_count' => 1])->save();
        $job->forceFill(['status' => 'completed'])->save();

        $this->assertSame(1, StoryUsageLedgerEntry::query()->count());
        $entry = StoryUsageLedgerEntry::query()->firstOrFail();
        $this->assertSame('completed', $entry->status);
        $this->assertNull($entry->cost);
        $this->assertNull($entry->currency);
        $this->assertNull($entry->cost_source);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson($this->url($project))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $job->uuid)
            ->assertJsonPath('data.0.capability', 'text_to_video')
            ->assertJsonPath('data.0.provider_key', 'catalog.alpha')
            ->assertJsonPath('data.0.model_key', 'catalog-alpha-default')
            ->assertJsonPath('data.0.operation_id', 'operations/story-history-1')
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.0.cost', null)
            ->assertJsonPath('data.0.currency', null)
            ->assertJsonPath('data.0.cost_source', null)
            ->assertJsonPath('data.0.cost_reported', false);
        $this->assertNotNull($response->json('data.0.completed_at'));
        $this->assertNotNull($response->json('data.0.ledger_recorded_at'));

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('story/private-output.mp4', $body);
        $this->assertStringNotContainsString('provider_metadata', $body);
        $this->assertStringNotContainsString('request_payload', $body);
        $this->assertStringNotContainsString('/file', $body);
        Http::assertNothingSent();
    }

    public function test_cost_is_stored_only_when_the_provider_reported_one_including_zero(): void
    {
        [$owner, $project, $workspace] = $this->project();
        $reported = StoryVideoGenerationJob::factory()->create([
            'story_workspace_id' => $workspace->id,
            'status' => 'completed',
            'completed_at' => now(),
            'created_at' => now()->subMinutes(3),
            'provider_metadata' => ['reported_cost' => ['amount' => 0, 'currency' => 'usd']],
        ]);
        $malformed = StoryVideoGenerationJob::factory()->create([
            'story_workspace_id' => $workspace->id,
            'status' => 'completed',
            'completed_at' => now(),
            'created_at' => now()->subMinutes(2),
            'provider_metadata' => ['reported_cost' => ['amount' => 'unknown']],
        ]);
        $failed = StoryVideoGenerationJob::factory()->create([
            'story_workspace_id' => $workspace->id,
            'status' => 'failed',
            'failed_at' => now(),
            'created_at' => now()->subMinute(),
            'error_code' => 'upstream_error',
            'error_message' => 'Request failed for https://example.test/v1/op?key=SECRET-TOKEN-123',
        ]);
        $pending = StoryVideoGenerationJob::factory()->create([
            'story_workspace_id' => $workspace->id,
            'status' => 'submitted',
            'created_at' => now(),
        ]);

        $this->assertSame(3, StoryUsageLedgerEntry::query()->count());

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson($this->url($project))
            ->assertOk()
            ->assertJsonCount(4, 'data');
        $this->assertSame(
            [$reported->uuid, $malformed->uuid, $failed->uuid, $pending->uuid],
            array_column($response->json('data'), 'id'),
        );
        $response
            ->assertJsonPath('data.0.cost', '0.000000')
            ->assertJsonPath('data.0.currency', 'USD')
            ->assertJsonPath('data.0.cost_source', 'provider_reported')
            ->assertJsonPath('data.0.cost_reported', true)
            ->assertJsonPath('data.1.cost', null)
            ->assertJsonPath('data.1.cost_reported', false)
            ->assertJsonPath('data.2.status', 'failed')
            ->assertJsonPath('data.2.error_code', 'upstream_error')
            ->assertJsonPath('data.2.cost', null)
            ->assertJsonPath('data.3.status', 'submitted')
            ->assertJsonPath('data.3.cost', null)
            ->assertJsonPath('data.3.ledger_recorded_at', null);
        $this->assertStringNotContainsString('SECRET-TOKEN-123', (string) $response->getContent());

        Http::assertNothingSent();
    }

    public function test_another_project_owner_cannot_read_the_history(): void
    {
        [$owner, $project, $workspace] = $this->project();
        StoryVideoGenerationJob::factory()->create([
            'story_workspace_id' => $workspace->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        [$otherOwner, $otherProject] = $this->project();

        $this->actingAs($otherOwner, 'sanctum')->getJson($this->url($project))->assertForbidden();
        $this->actingAs($otherOwner, 'sanctum')
            ->getJson($this->url($otherProject))
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $reviewer = User::factory()->reviewer()->create();
        $this->actingAs($reviewer, 'sanctum')->getJson($this->url($project))->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')->getJson($this->url($project))->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($owner, 'sanctum')->getJson($this->url($project))->assertOk()->assertJsonCount(1, 'data');

        Http::assertNothingSent();
    }

    public function test_anonymous_history_request_is_unauthorized(): void
    {
        [, $project] = $this->project();

        $this->getJson($this->url($project))->assertUnauthorized();
        Http::assertNothingSent();
    }

    /**
     * @return array{0: User, 1: Project, 2: StoryWorkspace}
     */
    private function project(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create();
        $workspace = StoryWorkspace::factory()->create(['project_id' => $project->id]);

        return [$owner, $project, $workspace];
    }

    private function url(Project $project): string
    {
        return "/api/v1/story/projects/{$project->uuid}/history";
    }
}
