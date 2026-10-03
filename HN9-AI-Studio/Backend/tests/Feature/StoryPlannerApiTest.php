<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Providers\AIServiceProvider;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryPlanVersion;
use App\Story\Support\StoryPlanDurationCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithProviderPlatform;
use Tests\TestCase;

final class StoryPlannerApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithProviderPlatform;

    public function test_unauthenticated_plan_requests_are_rejected(): void
    {
        $uuid = (string) Str::uuid();
        $plan = (string) Str::uuid();

        $this->getJson("/api/v1/story/projects/{$uuid}/plans")->assertUnauthorized();
        $this->postJson("/api/v1/story/projects/{$uuid}/plans", [])->assertUnauthorized();
        $this->getJson("/api/v1/story/projects/{$uuid}/plans/{$plan}")->assertUnauthorized();
        $this->postJson("/api/v1/story/projects/{$uuid}/plans/{$plan}/generate")->assertUnauthorized();
        $this->postJson("/api/v1/story/projects/{$uuid}/plans/{$plan}/regenerate")->assertUnauthorized();
        $this->getJson("/api/v1/story/projects/{$uuid}/plans/{$plan}/versions")->assertUnauthorized();
    }

    public function test_owner_can_create_and_read_plan(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $created = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans", [
                'title' => 'Cave Continuation',
                'idea' => 'Continue with Aarav entering the mountain cave.',
                'requested_duration_seconds' => 60,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.requested_duration_seconds', 60)
            ->assertJsonPath('data.scene_count_estimate', 2);

        $planId = $created->json('data.id');
        $this->assertTrue(Str::isUuid($planId));

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/plans/{$planId}")
            ->assertOk()
            ->assertJsonPath('data.idea', 'Continue with Aarav entering the mountain cave.');
    }

    public function test_duration_validation_rejects_too_short(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans", [
                'idea' => 'Too short',
                'requested_duration_seconds' => 10,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['requested_duration_seconds']);
    }

    public function test_successful_generation_persists_structured_plan_and_version(): void
    {
        $this->bootOpenAiTextRuntime();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'model' => 'configured-openai-model',
                'output_text' => $this->validPlanJson(60),
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30],
            ]),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$project->uuid}/bible", [
                'concept' => 'Aarav adventure',
                'tone' => 'Curious',
            ])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters", [
                'name' => 'Aarav',
                'clothing' => 'Blue hoodie',
            ])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/story/projects/{$project->uuid}/style", [
                'visual_style' => '3D cinematic animation',
            ])
            ->assertOk();

        $planId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans", [
                'idea' => 'Continue with Aarav entering the mountain cave.',
                'requested_duration_seconds' => 60,
            ])
            ->json('data.id');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans/{$planId}/generate")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.current_version.status', 'completed')
            ->assertJsonPath('data.current_version.plan.total_duration_seconds', 60)
            ->assertJsonPath('data.current_version.provider', 'openai');

        $this->assertCount(2, $response->json('data.current_version.plan.scenes'));
        $this->assertSame(0, $response->json('data.current_version.plan.scenes.0.start_second'));
        $this->assertSame(30, $response->json('data.current_version.plan.scenes.0.end_second'));
        $this->assertSame(30, $response->json('data.current_version.plan.scenes.1.start_second'));
        $this->assertSame(60, $response->json('data.current_version.plan.scenes.1.end_second'));
        $this->assertStringNotContainsString('sk-test', $response->getContent() ?: '');
        $this->assertSame(1, StoryPlanVersion::query()->count());

        Http::assertSent(function ($request): bool {
            $body = $request->body();

            return str_contains($request->url(), '/responses')
                && str_contains($body, 'Aarav')
                && str_contains($body, '3D cinematic animation')
                && str_contains($body, 'Story Details')
                && ! str_contains($body, 'Bible');
        });
    }

    public function test_malformed_ai_output_fails_without_fake_plan(): void
    {
        $this->bootOpenAiTextRuntime();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'model' => 'configured-openai-model',
                'output_text' => '{"title":"Incomplete"}',
            ]),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $planId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans", [
                'idea' => 'Broken output test',
                'requested_duration_seconds' => 30,
            ])
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans/{$planId}/generate")
            ->assertStatus(502);

        $plan = StoryPlan::query()->where('uuid', $planId)->firstOrFail();
        $this->assertSame('failed', $plan->status);
        $this->assertNull($plan->current_version_id);
        $version = StoryPlanVersion::query()->firstOrFail();
        $this->assertSame('failed', $version->status);
        $this->assertNull($version->plan);
    }

    public function test_provider_failure_marks_plan_failed(): void
    {
        $this->bootOpenAiTextRuntime();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'error' => ['message' => 'quota exceeded'],
            ], 429),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $planId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans", [
                'idea' => 'Provider fail',
                'requested_duration_seconds' => 30,
            ])
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans/{$planId}/generate")
            ->assertStatus(502);

        $this->assertSame('failed', StoryPlan::query()->firstOrFail()->status);
        $this->assertNull(StoryPlanVersion::query()->firstOrFail()->plan);
    }

    public function test_regeneration_creates_new_version_preserving_old(): void
    {
        $this->bootOpenAiTextRuntime();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push([
                    'model' => 'configured-openai-model',
                    'output_text' => $this->validPlanJson(60),
                ])
                ->push([
                    'model' => 'configured-openai-model',
                    'output_text' => $this->validPlanJson(60, 'More Emotional Cave'),
                ]),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $planId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans", [
                'idea' => 'Continue into cave',
                'requested_duration_seconds' => 60,
            ])
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans/{$planId}/generate")
            ->assertOk();

        $regen = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans/{$planId}/regenerate", [
                'instruction' => 'Make the second half more emotional.',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_version.version', 2)
            ->assertJsonPath('data.current_version.plan.title', 'More Emotional Cave');

        $this->assertSame(2, StoryPlanVersion::query()->count());
        $this->assertSame(2, $regen->json('data.current_version.version'));

        $versions = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/plans/{$planId}/versions")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $versions);
    }

    public function test_non_owner_cannot_access_plans(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = Project::factory()->for($owner)->create();

        $planId = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans", [
                'idea' => 'Secret plan',
                'requested_duration_seconds' => 30,
            ])
            ->json('data.id');

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/plans")
            ->assertForbidden();

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans/{$planId}/generate")
            ->assertForbidden();
    }

    public function test_planner_service_does_not_hardcode_openai(): void
    {
        $source = (string) file_get_contents(base_path('app/Story/Services/StoryPlannerService.php'));
        $this->assertStringContainsString('ProviderDispatcherInterface', $source);
        $this->assertStringNotContainsString('OpenAI', $source);
        $this->assertStringNotContainsString('api.openai.com', $source);
        $this->assertStringNotContainsString('gpt-4', $source);
    }

    public function test_m11_style_and_character_routes_still_work(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/style")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/characters")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/story/projects/{$project->uuid}/bible")
            ->assertOk();
    }

    private function bootOpenAiTextRuntime(): void
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

    private function validPlanJson(int $totalSeconds, string $title = 'Cave Continuation'): string
    {
        $calc = (new StoryPlanDurationCalculator)->calculate($totalSeconds);
        $scenes = [];
        foreach ($calc['segments'] as $segment) {
            $scenes[] = [
                'sequence' => $segment['sequence'],
                'start_second' => $segment['start_second'],
                'end_second' => $segment['end_second'],
                'duration_seconds' => $segment['duration_seconds'],
                'title' => 'Scene '.$segment['sequence'],
                'story' => 'Story beat '.$segment['sequence'],
                'characters' => ['Aarav'],
                'location' => 'Mountain cave',
                'dialogue' => ['Aarav: I can do this.'],
                'narration' => 'He steps forward.',
                'visual_prompt' => 'Boy in blue hoodie at cave mouth',
                'motion_prompt' => 'Slow push-in',
                'audio_direction' => 'Soft wind and footsteps',
                'continuity' => [
                    'previous_scene' => $segment['sequence'] === 1 ? '' : 'prior beat',
                    'next_scene' => 'continues',
                    'character_state' => 'curious',
                    'environment_state' => 'dim cave',
                ],
            ];
        }

        return json_encode([
            'title' => $title,
            'logline' => 'Aarav enters the cave.',
            'master_story' => 'Aarav continues his journey into the mountain cave.',
            'total_duration_seconds' => $totalSeconds,
            'scene_duration_target_seconds' => 30,
            'remainder_strategy' => $calc['remainder_strategy'],
            'scenes' => $scenes,
        ], JSON_THROW_ON_ERROR);
    }
}
