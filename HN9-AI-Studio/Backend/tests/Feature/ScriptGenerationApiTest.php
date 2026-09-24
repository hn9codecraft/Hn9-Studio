<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\ScriptSource;
use App\Models\Project;
use App\Models\PromptExecution;
use App\Models\Script;
use App\Models\User;
use App\Providers\AIServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\InteractsWithProviderPlatform;
use Tests\TestCase;

final class ScriptGenerationApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProviderPlatform;

    public function test_script_generation_requires_authentication(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Draft->value]);

        $this->postJson('/api/v1/projects/'.$project->uuid.'/scripts/generate', [
            'topic' => 'Reel hook about onboarding',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('scripts', 0);
        $this->assertDatabaseCount('generated_contents', 0);
    }

    public function test_user_cannot_generate_a_script_for_another_users_project(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Draft->value]);

        $this->actingAs($intruder, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts/generate', [
                'topic' => 'Stolen brief',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('scripts', 0);
    }

    public function test_provider_not_configured_creates_no_script(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Draft->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts/generate', [
                'topic' => 'Onboarding reel',
                'platform' => 'instagram',
                'language' => 'en',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'ai_provider_not_configured')
            ->assertJsonPath('context.reason', 'not_configured');

        $this->assertDatabaseCount('scripts', 0);
        $this->assertDatabaseCount('generated_contents', 0);
    }

    public function test_successful_generation_creates_an_ai_script_linked_to_generated_content(): void
    {
        $this->bootOpenAiRuntime();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'model' => 'configured-openai-model',
                'output_text' => '[0:00] Hook line for the reel.',
                'usage' => ['input_tokens' => 4, 'output_tokens' => 6, 'total_tokens' => 10],
            ]),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Draft->value]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts/generate', [
                'topic' => 'Onboarding reel',
                'platform' => 'instagram',
                'language' => 'en',
                'goal' => 'Book a demo',
                'duration' => '30s',
                'audience' => 'Founders',
                'tone' => 'direct',
                'cta' => 'Book a call',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.script.title', 'Onboarding reel')
            ->assertJsonPath('data.script.body', '[0:00] Hook line for the reel.')
            ->assertJsonPath('data.script.source', ScriptSource::Ai->value)
            ->assertJsonPath('data.dispatch.provider', 'openai');

        $this->assertStringNotContainsString('sk-test-openai-key', $response->getContent() ?: '');

        $script = Script::query()->first();
        $this->assertNotNull($script);
        $this->assertSame(ScriptSource::Ai->value, $script->source);
        $this->assertNotNull($script->generated_content_id);
        $this->assertSame('[0:00] Hook line for the reel.', $script->body);
        $this->assertDatabaseHas('generated_contents', [
            'id' => $script->generated_content_id,
            'project_id' => $project->id,
            'type' => 'script',
            'body' => '[0:00] Hook line for the reel.',
        ]);
        $this->assertDatabaseHas('prompt_executions', [
            'status' => ExecutionStatus::Completed->value,
            'template_key' => 'script',
        ]);
    }

    public function test_manual_script_is_not_overwritten_by_generation(): void
    {
        $this->bootOpenAiRuntime();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'model' => 'configured-openai-model',
                'output_text' => 'AI variation body',
            ]),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Draft->value]);
        $manual = Script::factory()->for($project)->create([
            'title' => 'Handmade VO',
            'body' => 'Keep this copy.',
            'source' => ScriptSource::Manual->value,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts/generate', [
                'topic' => 'New AI script',
                'language' => 'en',
            ])
            ->assertCreated()
            ->assertJsonPath('data.script.source', ScriptSource::Ai->value);

        $this->assertDatabaseCount('scripts', 2);
        $this->assertDatabaseHas('scripts', [
            'id' => $manual->id,
            'title' => 'Handmade VO',
            'body' => 'Keep this copy.',
            'source' => ScriptSource::Manual->value,
            'generated_content_id' => null,
        ]);
    }

    public function test_regeneration_creates_a_new_script_and_preserves_the_previous_one(): void
    {
        $this->bootOpenAiRuntime();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push([
                    'model' => 'configured-openai-model',
                    'output_text' => 'Original AI script',
                ])
                ->push([
                    'model' => 'configured-openai-model',
                    'output_text' => 'Regenerated AI script',
                ]),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Draft->value]);

        $first = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts/generate', [
                'topic' => 'Onboarding reel',
                'language' => 'en',
            ])
            ->assertCreated()
            ->json('data.script');

        $second = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts/'.$first['id'].'/regenerate', [
                'topic' => 'Onboarding reel',
                'language' => 'en',
            ])
            ->assertCreated()
            ->json('data.script');

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame('Regenerated AI script', $second['body']);
        $this->assertSame($first['id'], $second['parent_script_id']);
        $this->assertDatabaseHas('scripts', [
            'uuid' => $first['id'],
            'body' => 'Original AI script',
        ]);
        $this->assertDatabaseCount('scripts', 2);
        $this->assertDatabaseCount('generated_contents', 2);
    }

    public function test_ai_script_can_be_edited_without_losing_generation_trace(): void
    {
        $this->bootOpenAiRuntime();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'model' => 'configured-openai-model',
                'output_text' => 'Generated draft',
            ]),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Draft->value]);

        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts/generate', [
                'topic' => 'Onboarding reel',
                'language' => 'en',
            ])
            ->assertCreated()
            ->json('data.script');

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/projects/'.$project->uuid.'/scripts/'.$created['id'], [
                'body' => 'Edited by the producer',
            ])
            ->assertOk()
            ->assertJsonPath('data.body', 'Edited by the producer')
            ->assertJsonPath('data.source', ScriptSource::Ai->value)
            ->assertJsonPath('data.generated_content_id', $created['generated_content_id']);

        $this->assertDatabaseHas('generated_contents', [
            'uuid' => $created['generated_content_id'],
            'body' => 'Generated draft',
        ]);
    }

    public function test_provider_failure_creates_no_completed_script(): void
    {
        $this->bootOpenAiRuntime();
        Sleep::fake();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'error' => ['message' => 'overloaded'],
            ], 503),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Draft->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts/generate', [
                'topic' => 'Onboarding reel',
                'language' => 'en',
            ])
            ->assertStatus(502)
            ->assertJsonPath('error_code', 'ai_all_providers_failed');

        $this->assertDatabaseCount('scripts', 0);
        $this->assertDatabaseCount('generated_contents', 0);
        $this->assertTrue(PromptExecution::query()->where('status', ExecutionStatus::Failed->value)->exists());
    }

    public function test_archived_project_cannot_generate_a_script(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Archived->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts/generate', [
                'topic' => 'Onboarding reel',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'generation_project_not_editable');

        $this->assertDatabaseCount('scripts', 0);
    }

    public function test_validation_requires_a_topic(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Draft->value]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/scripts/generate', [
                'platform' => 'instagram',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['topic']);
    }

    private function bootOpenAiRuntime(): void
    {
        config()->set('ai.providers.openai', [
            'enabled' => true,
            'api_key' => 'sk-test-openai-key',
            'base_url' => 'https://api.openai.com/v1',
            'default_model' => 'configured-openai-model',
            'models' => ['configured-openai-model'],
            'priority' => 100,
            'timeout' => 5,
            'max_retries' => 0,
        ]);

        (new AIServiceProvider($this->app))->boot();

        $this->configurePlatform([
            'ai.routing.strategy' => 'priority',
            'ai.retry.jitter' => false,
            'ai.retry.delay_ms' => 1,
            'ai.retry.max_attempts' => 1,
        ]);
    }
}
