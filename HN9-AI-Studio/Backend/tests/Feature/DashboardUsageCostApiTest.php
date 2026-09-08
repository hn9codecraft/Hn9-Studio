<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Responses\UsageResponse;
use App\Enums\CostSource;
use App\Enums\ProjectStatus;
use App\Models\AgentExecution;
use App\Models\AiProvider;
use App\Models\Project;
use App\Models\PromptExecution;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Services\PromptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class DashboardUsageCostApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_usage_and_costs_are_rejected(): void
    {
        $this->getJson('/api/v1/dashboard/usage')->assertUnauthorized();
        $this->getJson('/api/v1/dashboard/costs')->assertUnauthorized();
    }

    public function test_invalid_date_and_from_after_to_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/usage?from=not-a-date')
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/costs?from=2026-09-10&to=2026-09-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);
    }

    public function test_empty_account_returns_honest_zeros_and_no_invented_cost(): void
    {
        $user = User::factory()->create();

        $usage = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/usage')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $usage['operations']);
        $this->assertNull($usage['tokens']['input']);
        $this->assertNull($usage['tokens']['output']);
        $this->assertNull($usage['tokens']['total']);
        $this->assertSame([], $usage['by_provider']);
        $this->assertSame([], $usage['by_model']);
        $this->assertSame([], $usage['timeline']);
        $this->assertArrayNotHasKey('estimated_cost', $usage);
        $this->assertArrayNotHasKey('rendered_prompt', $usage);

        $costs = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/costs')
            ->assertOk()
            ->json('data');

        $this->assertFalse($costs['has_records']);
        $this->assertSame([], $costs['totals']);
        $this->assertSame([], $costs['timeline']);
        $this->assertNotEmpty($costs['message']);
    }

    public function test_usage_aggregates_real_prompt_executions_and_keeps_null_tokens_unknown(): void
    {
        $user = User::factory()->create();
        $provider = AiProvider::factory()->create(['slug' => 'openai']);
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);
        $this->seedExecution($user, $project, $provider, [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'total_tokens' => 15,
            'model' => 'configured-text-model',
            'created_at' => '2026-09-05 10:00:00',
        ]);
        $this->seedExecution($user, $project, $provider, [
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'total_tokens' => null,
            'model' => 'configured-text-model',
            'created_at' => '2026-09-05 11:00:00',
        ]);

        $data = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/usage')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['operations']);
        $this->assertSame(10, $data['tokens']['input']);
        $this->assertSame(5, $data['tokens']['output']);
        $this->assertSame(15, $data['tokens']['total']);
        $this->assertSame(1, $data['tokens']['operations_with_tokens']);
        $this->assertSame(1, $data['tokens']['operations_without_tokens']);
        $this->assertSame('openai', $data['by_provider'][0]['provider']);
        $this->assertSame('configured-text-model', $data['by_model'][0]['model']);
        $this->assertSame('2026-09-05', $data['timeline'][0]['date']);
        $this->assertSame(2, $data['timeline'][0]['operations']);
    }

    public function test_usage_is_isolated_between_users_and_excludes_soft_deleted_projects(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $provider = AiProvider::factory()->create(['slug' => 'openai']);

        $owned = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active->value]);
        $this->seedExecution($owner, $owned, $provider, ['prompt_tokens' => 8, 'completion_tokens' => 2, 'total_tokens' => 10]);

        $foreign = Project::factory()->for($intruder)->create(['status' => ProjectStatus::Active->value]);
        $this->seedExecution($intruder, $foreign, $provider, ['prompt_tokens' => 99, 'completion_tokens' => 99, 'total_tokens' => 198]);

        $deleted = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active->value]);
        $this->seedExecution($owner, $deleted, $provider, ['prompt_tokens' => 50, 'completion_tokens' => 50, 'total_tokens' => 100]);
        $deleted->delete();

        $data = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/dashboard/usage')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['operations']);
        $this->assertSame(10, $data['tokens']['total']);
        $payload = json_encode($data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('198', $payload);
    }

    public function test_date_project_and_provider_filters(): void
    {
        $user = User::factory()->create();
        $openai = AiProvider::factory()->create(['slug' => 'openai']);
        $claude = AiProvider::factory()->create(['slug' => 'claude']);
        $alpha = Project::factory()->for($user)->create(['name' => 'Alpha', 'status' => ProjectStatus::Active->value]);
        $beta = Project::factory()->for($user)->create(['name' => 'Beta', 'status' => ProjectStatus::Active->value]);

        $this->seedExecution($user, $alpha, $openai, [
            'total_tokens' => 11,
            'prompt_tokens' => 11,
            'completion_tokens' => 0,
            'created_at' => '2026-09-01 10:00:00',
        ]);
        $this->seedExecution($user, $beta, $claude, [
            'total_tokens' => 22,
            'prompt_tokens' => 22,
            'completion_tokens' => 0,
            'created_at' => '2026-09-10 10:00:00',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/usage?from=2026-09-01&to=2026-09-02')
            ->assertOk()
            ->assertJsonPath('data.operations', 1)
            ->assertJsonPath('data.tokens.total', 11);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/usage?project='.$alpha->uuid)
            ->assertOk()
            ->assertJsonPath('data.operations', 1)
            ->assertJsonPath('data.tokens.total', 11);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/usage?provider=claude')
            ->assertOk()
            ->assertJsonPath('data.operations', 1)
            ->assertJsonPath('data.tokens.total', 22);
    }

    public function test_project_filter_does_not_reveal_another_users_project(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $foreign = Project::factory()->for($intruder)->create(['status' => ProjectStatus::Active->value]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/dashboard/usage?project='.$foreign->uuid)
            ->assertNotFound();
    }

    public function test_costs_ignore_unpriced_rows_and_aggregate_real_recorded_costs(): void
    {
        $user = User::factory()->create();
        $provider = AiProvider::factory()->create(['slug' => 'openai']);
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);

        $this->seedExecution($user, $project, $provider, [
            'total_tokens' => 10,
            'prompt_tokens' => 10,
            'completion_tokens' => 0,
            'cost' => null,
            'cost_source' => null,
        ]);
        $this->seedExecution($user, $project, $provider, [
            'total_tokens' => 20,
            'prompt_tokens' => 10,
            'completion_tokens' => 10,
            'cost' => 1.25,
            'currency' => 'USD',
            'cost_source' => CostSource::ProviderReported->value,
        ]);

        $costs = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboard/costs')
            ->assertOk()
            ->json('data');

        $this->assertTrue($costs['has_records']);
        $this->assertSame('1.250000', $costs['totals'][0]['amount']);
        $this->assertSame(1, $costs['totals'][0]['operations']);
        $this->assertSame('openai', $costs['by_provider'][0]['provider']);
        $this->assertSame(1, $costs['by_provider'][0]['operations']);
    }

    public function test_admin_usage_remains_owner_scoped(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $provider = AiProvider::factory()->create(['slug' => 'openai']);
        $memberProject = Project::factory()->for($member)->create(['status' => ProjectStatus::Active->value]);
        $this->seedExecution($member, $memberProject, $provider, ['total_tokens' => 40, 'prompt_tokens' => 40, 'completion_tokens' => 0]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/usage')
            ->assertOk()
            ->assertJsonPath('data.operations', 0);
    }

    public function test_public_payload_omits_secrets_prompts_and_integer_ids(): void
    {
        $user = User::factory()->create();
        $provider = AiProvider::factory()->create(['slug' => 'openai']);
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active->value]);
        $this->seedExecution($user, $project, $provider, [
            'prompt_tokens' => 4,
            'completion_tokens' => 6,
            'total_tokens' => 10,
            'rendered_prompt' => 'SECRET_PROMPT',
            'response' => 'SECRET_RESPONSE',
            'variables' => ['api_key' => 'sk-secret'],
        ]);

        $usage = $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard/usage')->assertOk()->json();
        $costs = $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard/costs')->assertOk()->json();
        $encoded = json_encode([$usage, $costs], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('SECRET_PROMPT', $encoded);
        $this->assertStringNotContainsString('SECRET_RESPONSE', $encoded);
        $this->assertStringNotContainsString('sk-secret', $encoded);
        $this->assertStringNotContainsString('api_key', $encoded);
        $this->assertArrayNotHasKey('id', $usage['data']);
        $this->assertArrayNotHasKey('user_id', $usage['data']);
        $this->assertArrayNotHasKey('project_id', $usage['data']);
    }

    public function test_provider_usage_persistence_stores_tokens_and_skips_unpriced_cost(): void
    {
        $user = User::factory()->create();
        $provider = AiProvider::factory()->create(['slug' => 'openai']);
        $project = Project::factory()->for($user)->create();
        $run = WorkflowRun::factory()->create(['project_id' => $project->id, 'user_id' => $user->id]);
        $agent = AgentExecution::factory()->create(['workflow_run_id' => $run->id, 'ai_provider_id' => $provider->id]);
        $execution = PromptExecution::factory()->create([
            'agent_execution_id' => $agent->id,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'total_tokens' => null,
            'cost' => null,
        ]);

        $unpriced = new UsageResponse(
            promptTokens: 12,
            completionTokens: 8,
            totalTokens: 20,
            cost: null,
            costSource: null,
            executionTimeMs: 90,
        );

        $updated = app(PromptService::class)->recordProviderUsage(
            $execution,
            $unpriced,
            'configured-text-model',
            'openai',
            120,
        );

        $this->assertSame(12, $updated->prompt_tokens);
        $this->assertSame(8, $updated->completion_tokens);
        $this->assertSame(20, $updated->total_tokens);
        $this->assertNull($updated->cost);
        $this->assertNull($updated->cost_source);
        $this->assertSame(120, $updated->latency_ms);
        $this->assertSame($provider->id, $updated->ai_provider_id);
        $this->assertSame(20, $agent->fresh()->tokens_used);
        $this->assertNull($agent->fresh()->cost);
    }

    public function test_provider_reported_and_configured_pricing_are_persisted(): void
    {
        $user = User::factory()->create();
        $provider = AiProvider::factory()->create(['slug' => 'openrouter']);
        $project = Project::factory()->for($user)->create();
        $run = WorkflowRun::factory()->create(['project_id' => $project->id, 'user_id' => $user->id]);
        $agent = AgentExecution::factory()->create(['workflow_run_id' => $run->id]);
        $reported = PromptExecution::factory()->create(['agent_execution_id' => $agent->id]);
        $priced = PromptExecution::factory()->create(['agent_execution_id' => $agent->id]);

        app(PromptService::class)->recordProviderUsage(
            $reported,
            new UsageResponse(3, 1, 4, 0.0025, 'USD', 40, CostSource::ProviderReported->value),
            'vendor-a/model-one',
            'openrouter',
            40,
        );
        app(PromptService::class)->recordProviderUsage(
            $priced,
            new UsageResponse(10, 20, 30, 0.00033, 'USD', 50, CostSource::ConfiguredPricing->value),
            'vendor-a/model-one',
            'openrouter',
            50,
        );

        $this->assertSame(CostSource::ProviderReported->value, $reported->fresh()->cost_source);
        $this->assertSame('0.002500', $reported->fresh()->cost);
        $this->assertSame(CostSource::ConfiguredPricing->value, $priced->fresh()->cost_source);
        $this->assertNotNull($agent->fresh()->cost);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedExecution(User $user, Project $project, AiProvider $provider, array $overrides): PromptExecution
    {
        $run = WorkflowRun::factory()->create([
            'project_id' => $project->id,
            'user_id' => $user->id,
        ]);
        $agent = AgentExecution::factory()->create([
            'workflow_run_id' => $run->id,
            'ai_provider_id' => $provider->id,
        ]);

        $attributes = array_merge([
            'agent_execution_id' => $agent->id,
            'ai_provider_id' => $provider->id,
            'template_key' => 'blog',
            'model' => 'configured-text-model',
            'status' => 'completed',
        ], $overrides);

        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $execution = PromptExecution::factory()->create($attributes);

        if (is_string($createdAt)) {
            $execution->forceFill([
                'created_at' => Carbon::parse($createdAt),
                'updated_at' => Carbon::parse($createdAt),
            ])->save();
        }

        return $execution;
    }
}
