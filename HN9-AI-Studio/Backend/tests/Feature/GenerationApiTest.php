<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Contracts\ProviderDispatcherInterface;
use App\AI\Exceptions\AllProvidersFailedException;
use App\AI\Execution\DispatchResult;
use App\AI\Responses\TextResponse;
use App\AI\Support\Capability;
use App\AI\Support\Modality;
use App\Models\GeneratedAsset;
use App\Models\GeneratedContent;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class GenerationApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * blog.md declares placeholders the brand context does not yet source —
     * keywords, service, cta, seo_rules, brand_rules and the word-count trio.
     * Until a Brand Brain layer supplies them, the caller passes them in.
     *
     * @var array<string, mixed>
     */
    private const BLOG_TEMPLATE_VARIABLES = [
        'keywords' => ['ai automation', 'workflow automation'],
        'keywords_focus' => 'ai automation',
        'service' => 'Automation consulting',
        'cta' => 'Book a discovery call',
        'seo_rules' => 'One H1, descriptive H2s, no keyword stuffing.',
        'brand_rules' => 'Never overpromise. Plain language.',
        'word_count' => 1200,
        'key_points' => ['time saved', 'fewer errors'],
    ];

    public function test_generate_endpoint_runs_the_pipeline_and_persists_content_and_asset(): void
    {
        $user = User::factory()->create();
        // Pinned: the factory randomises status over ['draft','active','archived']
        // and an archived project is not editable.
        $project = Project::factory()->for($user)->create(['status' => 'draft']);

        $this->fakeDispatcher('Generated blog body');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/generate', [
                'deliverable_type' => 'blog',
                'language' => 'en',
                'topic' => 'AI automation',
                'payload' => self::BLOG_TEMPLATE_VARIABLES + ['title' => 'Hello world'],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.input.deliverable_type', 'blog')
            ->assertJsonPath('data.input.language', 'en')
            ->assertJsonPath('data.content.body', 'Generated blog body')
            ->assertJsonPath('data.asset.provider', 'openai')
            ->assertJsonPath('data.dispatch.provider', 'openai');

        $this->assertDatabaseHas('project_inputs', [
            'project_id' => $project->id,
            'deliverable_type' => 'blog',
        ]);

        $this->assertDatabaseHas('generated_contents', [
            'project_id' => $project->id,
            'body' => 'Generated blog body',
        ]);

        $this->assertDatabaseHas('generated_assets', [
            'project_id' => $project->id,
            'provider' => 'openai',
        ]);
    }

    public function test_generate_endpoint_rejects_a_deliverable_outside_the_prompt_catalog(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => 'draft']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/generate', [
                'deliverable_type' => 'not_a_template',
                'language' => 'en',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'generation_unsupported_deliverable');

        $this->assertDatabaseCount('generated_contents', 0);
    }

    public function test_generate_endpoint_returns_the_error_envelope_when_every_provider_fails(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => 'draft']);

        $dispatcher = Mockery::mock(ProviderDispatcherInterface::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(
            AllProvidersFailedException::make(Capability::Text, []),
        );
        $this->instance(ProviderDispatcherInterface::class, $dispatcher);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/generate', [
                'deliverable_type' => 'blog',
                'language' => 'en',
                'topic' => 'AI automation',
                'payload' => self::BLOG_TEMPLATE_VARIABLES,
            ]);

        // A provider failure must surface as the canonical envelope, not a 500.
        $response->assertStatus(502)
            ->assertJsonPath('error_code', 'ai_all_providers_failed');

        $this->assertDatabaseCount('generated_contents', 0);
    }

    public function test_generate_endpoint_reports_a_prompt_variable_the_request_cannot_fill(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => 'draft']);

        // No payload, so the blog template's keywords/service/cta placeholders
        // have no value. That is a 422, not a 500.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/generate', [
                'deliverable_type' => 'blog',
                'language' => 'en',
                'topic' => 'AI automation',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'generation_missing_prompt_variable')
            ->assertJsonPath('context.template_key', 'blog');

        $this->assertDatabaseCount('generated_contents', 0);
    }

    public function test_generate_endpoint_rejects_a_project_that_cannot_accept_requests(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['status' => 'archived']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/generate', [
                'deliverable_type' => 'blog',
                'language' => 'en',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'generation_project_not_editable');
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

    public function test_preview_endpoint_returns_the_validated_request_payload_without_persisting(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects/'.$project->uuid.'/generate/preview', [
                'deliverable_type' => 'caption',
                'language' => 'en',
                'payload' => ['copy' => 'Preview content'],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.deliverable_type', 'caption')
            ->assertJsonPath('data.payload.copy', 'Preview content');

        $this->assertDatabaseCount('project_inputs', 0);
    }

    public function test_generation_history_returns_inputs_contents_and_assets(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $projectInput = $project->inputs()->create([
            'user_id' => $user->id,
            'type' => 'brief',
            'deliverable_type' => 'post',
            'platform' => 'instagram',
            'language' => 'en',
            'payload' => ['caption' => 'Hello'],
            'source' => 'api',
        ]);

        GeneratedContent::factory()->for($project)->create(['type' => 'caption']);
        GeneratedAsset::factory()->for($project)->create(['type' => 'image']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects/'.$project->uuid.'/generation-history');

        $response->assertStatus(200)
            ->assertJsonPath('data.inputs.0.deliverable_type', 'post')
            ->assertJsonCount(1, 'data.contents')
            ->assertJsonCount(1, 'data.assets');
    }
}
