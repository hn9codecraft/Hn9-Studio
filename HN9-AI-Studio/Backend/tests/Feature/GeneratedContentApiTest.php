<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Contracts\ProviderDispatcherInterface;
use App\AI\Execution\DispatchResult;
use App\AI\Responses\TextResponse;
use App\AI\Support\Modality;
use App\Models\AgentExecution;
use App\Models\GeneratedContent;
use App\Models\Project;
use App\Models\PromptExecution;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class GeneratedContentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_content_from_projects_the_user_owns(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $mine = Project::factory()->for($user)->create();
        $theirs = Project::factory()->for($other)->create();

        GeneratedContent::factory()->count(2)->for($mine)->create();
        GeneratedContent::factory()->count(3)->for($theirs)->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-content')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta'])
            ->assertJsonCount(2, 'data');

        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_index_lets_an_admin_list_across_every_project(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create();

        GeneratedContent::factory()->count(3)->for(Project::factory()->for($owner))->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/generated-content')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_index_paginates(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        GeneratedContent::factory()->count(7)->for($project)->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-content?perPage=3&page=2')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');

        $this->assertSame(2, $response->json('meta.page'));
        $this->assertSame(3, $response->json('meta.perPage'));
        $this->assertSame(7, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.lastPage'));
    }

    public function test_index_filters_by_status_type_and_language(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        GeneratedContent::factory()->for($project)->create([
            'status' => 'approved', 'type' => 'blog', 'language' => 'en',
        ]);
        GeneratedContent::factory()->for($project)->create([
            'status' => 'draft', 'type' => 'caption', 'language' => 'hi',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-content?status=approved')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'blog');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-content?type=caption')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.language', 'hi');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-content?language=en')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_project_provider_template_and_favorite(): void
    {
        $user = User::factory()->create();
        $wanted = Project::factory()->for($user)->create();
        $ignored = Project::factory()->for($user)->create();

        GeneratedContent::factory()->for($wanted)->create([
            'metadata' => ['provider' => 'openai'],
            'structured' => ['template_key' => 'blog'],
            'is_favorite' => true,
        ]);
        GeneratedContent::factory()->for($ignored)->create([
            'metadata' => ['provider' => 'claude'],
            'structured' => ['template_key' => 'caption'],
            'is_favorite' => false,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-content?project='.$wanted->uuid)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider', 'openai');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-content?provider=claude')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.template_key', 'caption');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-content?template=blog')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider', 'openai');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-content?favorite=true')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_favorite', true);
    }

    public function test_index_filters_by_date_and_supports_search_and_sorting(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        GeneratedContent::factory()->for($project)->create([
            'title' => 'Alpha announcement',
            'created_at' => '2026-01-05 10:00:00',
        ]);
        GeneratedContent::factory()->for($project)->create([
            'title' => 'Beta announcement',
            'created_at' => '2026-02-05 10:00:00',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-content?search=Alpha')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Alpha announcement');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-content?date=2026-02-05')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Beta announcement');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-content?sort=title&order=asc')
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Alpha announcement');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-content?sort=title&order=desc')
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Beta announcement');
    }

    public function test_show_returns_content(): void
    {
        $user = User::factory()->create();
        $content = GeneratedContent::factory()
            ->for(Project::factory()->for($user))
            ->create(['title' => 'Launch copy']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-content/'.$content->uuid)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $content->uuid)
            ->assertJsonPath('data.title', 'Launch copy')
            ->assertJsonPath('data.is_favorite', false);
    }

    public function test_destroy_soft_deletes_content(): void
    {
        $user = User::factory()->create();
        $content = GeneratedContent::factory()
            ->for(Project::factory()->for($user))
            ->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/generated-content/'.$content->uuid)
            ->assertStatus(204);

        $this->assertSoftDeleted('generated_contents', ['id' => $content->id]);

        // A soft-deleted record leaves the list and the show endpoint.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-content')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-content/'.$content->uuid)
            ->assertStatus(404);
    }

    public function test_update_allows_title_status_and_metadata(): void
    {
        $user = User::factory()->create();
        $content = GeneratedContent::factory()
            ->for(Project::factory()->for($user))
            ->create(['title' => 'Original', 'status' => 'draft', 'metadata' => ['foo' => 'bar']]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/generated-content/'.$content->uuid, [
                'title' => 'Updated title',
                'status' => 'approved',
                'metadata' => ['foo' => 'baz'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Updated title')
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.metadata.foo', 'baz');

        $this->assertDatabaseHas('generated_contents', [
            'id' => $content->id,
            'title' => 'Updated title',
            'status' => 'approved',
        ]);
    }

    public function test_approve_sets_the_status_to_approved(): void
    {
        $user = User::factory()->create();
        $content = GeneratedContent::factory()
            ->for(Project::factory()->for($user))
            ->create(['status' => 'draft']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/generated-content/'.$content->uuid.'/approve')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('generated_contents', [
            'id' => $content->id,
            'status' => 'approved',
        ]);
    }

    public function test_favorite_and_unfavorite_toggle_the_flag(): void
    {
        $user = User::factory()->create();
        $content = GeneratedContent::factory()
            ->for(Project::factory()->for($user))
            ->create(['is_favorite' => false]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/generated-content/'.$content->uuid.'/favorite')
            ->assertStatus(200)
            ->assertJsonPath('data.is_favorite', true);

        $this->assertDatabaseHas('generated_contents', ['id' => $content->id, 'is_favorite' => true]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/generated-content/'.$content->uuid.'/favorite')
            ->assertStatus(200)
            ->assertJsonPath('data.is_favorite', false);

        $this->assertDatabaseHas('generated_contents', ['id' => $content->id, 'is_favorite' => false]);
    }

    public function test_favorite_is_idempotent(): void
    {
        $user = User::factory()->create();
        $content = GeneratedContent::factory()
            ->for(Project::factory()->for($user))
            ->create(['is_favorite' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/generated-content/'.$content->uuid.'/favorite')
            ->assertStatus(200)
            ->assertJsonPath('data.is_favorite', true);
    }

    public function test_regenerate_reuses_the_recorded_prompt_variables(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => 'draft']);

        $workflowRun = WorkflowRun::factory()->for($project)->create();
        $agentExecution = AgentExecution::factory()->for($workflowRun)->create();

        // The original render's variables are the faithful basis for a re-run.
        PromptExecution::factory()->for($agentExecution)->create([
            'template_key' => 'blog',
            'variables' => [
                'keywords' => 'ai automation',
                'keywords_focus' => 'ai automation',
                'service' => 'Automation consulting',
                'cta' => 'Book a call',
                'seo_rules' => 'One H1.',
                'brand_rules' => 'Plain language.',
                'word_count' => '1200',
                'key_points' => 'time saved',
            ],
        ]);

        $content = GeneratedContent::factory()->for($project)->create([
            'type' => 'blog',
            'language' => 'en',
            'channel' => 'instagram',
            'title' => 'AI automation',
            'agent_execution_id' => $agentExecution->id,
            'structured' => ['template_key' => 'blog'],
        ]);

        $this->fakeDispatcher('Regenerated blog body');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/generated-content/'.$content->uuid.'/regenerate')
            ->assertStatus(201)
            ->assertJsonPath('data.content.body', 'Regenerated blog body')
            ->assertJsonPath('data.content.type', 'blog')
            ->assertJsonPath('data.regenerated_from', $content->uuid)
            ->assertJsonPath('data.dispatch.provider', 'openai');

        // The original is untouched; regeneration produces a new record.
        $this->assertDatabaseCount('generated_contents', 2);
        $this->assertDatabaseHas('generated_contents', [
            'id' => $content->id,
            'body' => $content->body,
        ]);
    }

    public function test_regenerate_accepts_explicit_variable_overrides(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => 'draft']);

        // No agent execution, so nothing is recovered and the caller must supply
        // the template's variables.
        $content = GeneratedContent::factory()->for($project)->create([
            'type' => 'blog',
            'language' => 'en',
            'title' => 'AI automation',
            'structured' => [],
        ]);

        $this->fakeDispatcher('Override body');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/generated-content/'.$content->uuid.'/regenerate', [
                'variables' => [
                    'keywords' => 'automation',
                    'keywords_focus' => 'automation',
                    'service' => 'Consulting',
                    'cta' => 'Get in touch',
                    'seo_rules' => 'One H1.',
                    'brand_rules' => 'Be plain.',
                    'word_count' => 900,
                    'key_points' => 'speed',
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.content.body', 'Override body');
    }

    public function test_regenerate_reports_variables_it_cannot_fill(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => 'draft']);

        $content = GeneratedContent::factory()->for($project)->create([
            'type' => 'blog',
            'language' => 'en',
            'title' => 'AI automation',
            'structured' => [],
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/generated-content/'.$content->uuid.'/regenerate')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'generation_missing_prompt_variable');
    }

    public function test_regenerate_validates_its_overrides(): void
    {
        $user = User::factory()->create();
        $content = GeneratedContent::factory()
            ->for(Project::factory()->for($user)->create(['status' => 'draft']))
            ->create(['type' => 'blog']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/generated-content/'.$content->uuid.'/regenerate', [
                'variables' => 'not-an-array',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variables');
    }

    public function test_another_users_content_is_forbidden_on_every_endpoint(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $content = GeneratedContent::factory()
            ->for(Project::factory()->for($owner))
            ->create();

        $uuid = $content->uuid;

        $this->actingAs($intruder, 'sanctum')->getJson('/api/v1/generated-content/'.$uuid)->assertStatus(403);
        $this->actingAs($intruder, 'sanctum')->deleteJson('/api/v1/generated-content/'.$uuid)->assertStatus(403);
        $this->actingAs($intruder, 'sanctum')->postJson('/api/v1/generated-content/'.$uuid.'/favorite')->assertStatus(403);
        $this->actingAs($intruder, 'sanctum')->deleteJson('/api/v1/generated-content/'.$uuid.'/favorite')->assertStatus(403);
        $this->actingAs($intruder, 'sanctum')->postJson('/api/v1/generated-content/'.$uuid.'/regenerate')->assertStatus(403);

        $this->assertDatabaseHas('generated_contents', ['id' => $content->id, 'deleted_at' => null]);
    }

    public function test_every_endpoint_requires_authentication(): void
    {
        $content = GeneratedContent::factory()->for(Project::factory())->create();

        $this->getJson('/api/v1/generated-content')->assertStatus(401);
        $this->getJson('/api/v1/generated-content/'.$content->uuid)->assertStatus(401);
        $this->deleteJson('/api/v1/generated-content/'.$content->uuid)->assertStatus(401);
        $this->postJson('/api/v1/generated-content/'.$content->uuid.'/favorite')->assertStatus(401);
        $this->deleteJson('/api/v1/generated-content/'.$content->uuid.'/favorite')->assertStatus(401);
        $this->postJson('/api/v1/generated-content/'.$content->uuid.'/regenerate')->assertStatus(401);
    }

    public function test_an_admin_may_act_on_another_users_content(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create();

        $content = GeneratedContent::factory()
            ->for(Project::factory()->for($owner))
            ->create(['is_favorite' => false]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/generated-content/'.$content->uuid.'/favorite')
            ->assertStatus(200)
            ->assertJsonPath('data.is_favorite', true);
    }

    public function test_unknown_uuid_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/generated-content/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }

    private function fakeDispatcher(string $text, string $provider = 'openai'): void
    {
        $dispatcher = Mockery::mock(ProviderDispatcherInterface::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturn(new DispatchResult(
            providerKey: $provider,
            response: new TextResponse(text: $text),
            modality: Modality::Text,
            durationMs: 42,
        ));

        $this->instance(ProviderDispatcherInterface::class, $dispatcher);
    }
}
