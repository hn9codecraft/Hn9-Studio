<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use App\Providers\AIServiceProvider;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Story\Models\StoryBible;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryPlanVersion;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneAudio;
use App\Story\Models\StoryStyleBible;
use App\Story\Models\StoryStyleReference;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Support\StoryPlanDurationCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\InteractsWithProviderPlatform;
use Tests\TestCase;

final class StoryQuickStartTest extends TestCase
{
    use InteractsWithProviderPlatform;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_templates_require_auth_and_hide_the_scaffold(): void
    {
        $this->getJson('/api/v1/story/templates')->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/story/templates')
            ->assertOk()
            ->assertJsonPath('data.templates.0.key', 'company_journey')
            ->assertJsonPath('data.templates.1.key', 'product_ad')
            ->assertJsonPath('data.templates.2.key', 'reel')
            ->assertJsonMissing(['idea_scaffold' => true]);

        $this->assertStringNotContainsString('idea_scaffold', $this->actingAs($user, 'sanctum')->getJson('/api/v1/story/templates')->getContent());
    }

    public function test_company_journey_creates_one_reviewable_plan_and_stops(): void
    {
        $this->bootOpenAiTextRuntime();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push([
                    'model' => 'configured-openai-model',
                    'output_text' => $this->validPlanJson(180),
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30],
                ])
                ->push([
                    'model' => 'configured-openai-model',
                    'output_text' => $this->validPlanJson(40),
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30],
                ]),
        ]);
        [$user, $project] = $this->ownerProject();
        $marker = 'PROMPT-SECRET-MARKER';

        $response = $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project), $this->companyPayload([
                'topic' => 'Hn9 Journey from a desk to a studio',
                'fields' => ['company_name' => 'Hn9', 'milestones' => $marker, 'vision' => 'A studio'],
                'idempotency_key' => 'journey-1',
            ]))
            ->assertOk()
            ->assertJsonPath('data.plan_generation', 'ready_for_review')
            ->assertJsonPath('data.plan.review_status', 'ready_for_review')
            ->assertJsonPath('data.plan.status', 'completed')
            ->assertJsonPath('data.template_key', 'company_journey');

        $this->assertStringNotContainsString($marker, $response->getContent());
        $this->assertStringNotContainsString('sk-test-openai-key', $response->getContent());
        Http::assertSentCount(1);

        $plan = StoryPlan::query()->sole();
        $version = StoryPlanVersion::query()->sole();
        $this->assertSame($version->id, $plan->current_version_id);
        $this->assertNull($version->approved_at);
        $this->assertSame('Hn9 Journey from a desk to a studio', StoryBible::query()->sole()->concept);
        $this->assertSame('Cinematic realism', StoryStyleBible::query()->sole()->visual_style);
        $this->assertSame('16:9', StoryBible::query()->sole()->aspect_ratio);
        $this->assertSame(['Aarav', 'Founder', 'Team'], StoryCharacter::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame(['draft', 'draft', 'draft'], StoryCharacter::query()->orderBy('name')->pluck('status')->all());
        $this->assertFalse($response->json('data.plan_estimate.available'));
        $this->assertNothingProduced();

        $log = ActivityLog::query()->where('action', 'story.quick_start')->sole();
        $this->assertSame('company_journey', $log->properties['template']);
        $this->assertSame(180, $log->properties['duration_seconds']);
        $this->assertSame('ready_for_review', $log->properties['outcome']);
        $this->assertLessThanOrEqual(80, mb_strlen((string) $log->properties['topic']));
        $this->assertStringNotContainsString($marker, json_encode($log->properties, JSON_THROW_ON_ERROR));

        $direct = Project::factory()->for($user)->create();
        $planId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$direct->uuid}/plans", [
                'idea' => 'Direct generate stays at the plan',
                'requested_duration_seconds' => 40,
            ])
            ->json('data.id');
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$direct->uuid}/plans/{$planId}/generate")
            ->assertOk();

        $this->assertSame(0, StoryReel::query()->count());
        $this->assertSame(0, StoryScene::query()->count());
        Http::assertSentCount(2);
    }

    public function test_cast_from_plan_is_capped_and_makes_no_extra_provider_call(): void
    {
        $this->fakePlan(40, $this->namedPlan(40, array_map(static fn (int $n): string => 'Extra '.$n, range(1, 9))));
        [$user, $project] = $this->ownerProject();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project), $this->companyPayload([
                'duration_seconds' => 40,
                'idempotency_key' => 'cast-cap',
            ]))
            ->assertOk()
            ->assertJsonPath('data.plan_generation', 'ready_for_review');

        Http::assertSentCount(1);
        $names = StoryCharacter::query()->pluck('name')->all();
        $this->assertContains('Founder', $names);
        $this->assertContains('Extra 1', $names);
        $this->assertContains('Extra 8', $names);
        $this->assertNotContains('Extra 9', $names);
        $this->assertSame(10, StoryCharacter::query()->count());
        $this->assertSame(0, StoryCharacterReference::query()->count());
    }

    public function test_not_connected_saves_the_draft_and_sends_nothing(): void
    {
        [$user, $project] = $this->ownerProject();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project), $this->companyPayload(['idempotency_key' => 'offline']))
            ->assertOk()
            ->assertJsonPath('data.plan_generation', 'not_connected')
            ->assertJsonPath('data.plan.status', 'draft')
            ->assertJsonPath('data.plan.review_status', null);

        Http::assertNothingSent();
        $this->assertSame(0, StoryPlanVersion::query()->count());
        $this->assertSame('How Hn9 began', StoryBible::query()->sole()->concept);
        $this->assertSame('draft', StoryPlan::query()->sole()->status);
    }

    public function test_budget_blocks_generation_after_the_draft_is_saved(): void
    {
        $this->fakePlan(40);
        [$user, $project] = $this->ownerProject();
        $workspace = app(StoryWorkspaceServiceInterface::class)->workspaceForProject($project);
        $workspace->forceFill(['budget_amount' => 0.01, 'budget_currency' => 'USD'])->save();
        config()->set('story_cost.text.per_1k_tokens', 50);

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project), $this->companyPayload(['duration_seconds' => 40, 'idempotency_key' => 'budget']))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_budget_exceeded')
            ->assertJsonPath('context.drafts_saved', true);

        Http::assertNothingSent();
        $this->assertSame(0, StoryPlanVersion::query()->count());
        $this->assertSame('draft', StoryPlan::query()->sole()->status);
        $this->assertSame('How Hn9 began', StoryBible::query()->sole()->concept);
    }

    public function test_planner_failure_keeps_the_draft_and_hides_provider_details(): void
    {
        [$user, $project] = $this->ownerProject();
        $this->bootOpenAiTextRuntime();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'sk-test-openai-key leaked']], 500),
        ]);

        $failed = $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project), $this->companyPayload(['idempotency_key' => 'boom']))
            ->assertOk()
            ->assertJsonPath('data.plan_generation', 'failed');

        $this->assertStringNotContainsString('sk-test-openai-key', (string) $failed->json('data.error'));
        $this->assertSame('failed', StoryPlan::query()->sole()->status);
        $this->assertSame('failed', StoryPlanVersion::query()->sole()->status);
        $this->assertSame('How Hn9 began', StoryBible::query()->sole()->concept);

        $other = Project::factory()->for($user)->create();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(['output_text' => '{"title":"nope"}']),
        ]);
        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($other), $this->companyPayload(['idempotency_key' => 'invalid-json']))
            ->assertOk()
            ->assertJsonPath('data.plan_generation', 'failed');
        $this->assertSame(['How Hn9 began', 'How Hn9 began'], StoryBible::query()->orderBy('id')->pluck('concept')->all());
    }

    public function test_conflict_lists_existing_work_and_overwrite_keeps_it(): void
    {
        $this->fakePlan(40);
        [$user, $project] = $this->ownerProject();
        $characterId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters", ['name' => 'Founder', 'short_description' => 'Keep me'])
            ->assertCreated()
            ->json('data.id');
        $character = StoryCharacter::query()->where('uuid', $characterId)->firstOrFail();
        $reference = StoryCharacterReference::factory()->approved()->create(['story_character_id' => $character->id]);
        $character->forceFill(['approved_reference_id' => $reference->id])->save();
        $oldPlan = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans", [
                'title' => 'Old plan',
                'idea' => 'Already here',
                'requested_duration_seconds' => 40,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project), $this->companyPayload(['duration_seconds' => 40]))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_quick_start_conflict')
            ->assertJsonPath('context.plans.0.id', $oldPlan);

        $this->assertSame(1, StoryPlan::query()->count());
        Http::assertNothingSent();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project), $this->companyPayload([
                'duration_seconds' => 40,
                'overwrite' => true,
                'idempotency_key' => 'overwrite-1',
            ]))
            ->assertOk()
            ->assertJsonPath('data.plan_generation', 'ready_for_review');

        $this->assertSame(2, StoryPlan::query()->count());
        $this->assertNotNull(StoryPlan::query()->where('uuid', $oldPlan)->first());
        $this->assertSame(1, StoryCharacter::query()->where('name', 'Founder')->count());
        $this->assertSame($reference->id, $character->fresh()->approved_reference_id);
        Http::assertSentCount(1);
    }

    public function test_the_same_idempotency_key_does_not_run_twice(): void
    {
        $this->fakePlan(40);
        [$user, $project] = $this->ownerProject();
        $payload = $this->companyPayload(['duration_seconds' => 40, 'idempotency_key' => 'same-key']);

        $first = $this->actingAs($user, 'sanctum')->postJson($this->url($project), $payload)->assertOk()->json('data.plan.id');
        $second = $this->actingAs($user, 'sanctum')->postJson($this->url($project), $payload)->assertOk()->json('data.plan.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, StoryPlan::query()->count());
        Http::assertSentCount(1);
    }

    public function test_a_held_lock_rejects_a_second_quick_start(): void
    {
        [$user, $project] = $this->ownerProject();
        $workspace = app(StoryWorkspaceServiceInterface::class)->workspaceForProject($project);
        $lock = Cache::lock('story-quick-start:'.$workspace->id, 120);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($user, 'sanctum')
                ->postJson($this->url($project), $this->companyPayload(['idempotency_key' => 'locked']))
                ->assertStatus(409)
                ->assertJsonPath('error_code', 'story_quick_start_in_progress');
        } finally {
            $lock->release();
        }

        $this->assertSame(0, StoryPlan::query()->count());
        Http::assertNothingSent();
    }

    public function test_validation_rejects_unknown_choices(): void
    {
        [$user, $project] = $this->ownerProject();
        $post = fn (array $payload) => $this->actingAs($user, 'sanctum')->postJson($this->url($project), $payload);

        $post($this->companyPayload(['duration_seconds' => 45]))->assertStatus(422);
        $post($this->companyPayload(['aspect_ratio' => '1:1']))->assertStatus(422);
        $post($this->companyPayload(['language' => 'xx']))->assertStatus(422);
        $post($this->companyPayload(['template' => 'not-a-template']))->assertStatus(422);
        $post($this->companyPayload(['fields' => ['company_name' => 'Hn9', 'secret' => 'no']]))->assertStatus(422);
        $post(['template' => 'company_journey', 'topic' => 'How Hn9 began', 'duration_seconds' => 40, 'fields' => []])->assertStatus(422);
        $post(['template' => 'product_ad', 'topic' => 'A bottle', 'duration_seconds' => 40, 'fields' => ['product_name' => 'Bottle']])->assertStatus(422);
        $characters = [];
        foreach (range(1, 5) as $n) {
            $characters[] = ['name' => 'Person '.$n];
        }
        $post($this->companyPayload(['characters' => $characters]))->assertStatus(422);
        $this->assertSame(0, StoryPlan::query()->count());
    }

    public function test_estimate_has_no_side_effects_and_prices_the_preview(): void
    {
        [$user, $project] = $this->ownerProject();
        $before = $this->rowCounts();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project).'/estimate', $this->companyPayload(['duration_seconds' => 40]))
            ->assertOk();

        $this->assertSame($before, $this->rowCounts());
        Http::assertNothingSent();
        $this->assertSame('rough full-video estimate', $response->json('data.full_video_preview.label'));
        $this->assertSame(40, $response->json('data.full_video_preview.video.quantity'));
        $this->assertEquals(0.12, $response->json('data.full_video_preview.video.rate_usd'));
        $this->assertEquals(4.8, $response->json('data.full_video_preview.video.amount_usd'));
        $this->assertSame(600, (int) $response->json('data.full_video_preview.voice.quantity'));
        $this->assertEquals(0.048, $response->json('data.full_video_preview.voice.amount_usd'));
        $this->assertEquals(4.848, $response->json('data.full_video_preview.total_usd'));
        $this->assertFalse($response->json('data.plan_estimate.available'));
    }

    public function test_authorization_and_throttle_middleware(): void
    {
        $this->postJson('/api/v1/story/templates')->assertStatus(405);
        $this->getJson('/api/v1/story/templates')->assertUnauthorized();
        [$user, $project] = $this->ownerProject();
        $other = User::factory()->create();

        $this->postJson($this->url($project), $this->companyPayload())->assertUnauthorized();
        $this->actingAs($other, 'sanctum')->postJson($this->url($project), $this->companyPayload())->assertForbidden();
        $this->actingAs($other, 'sanctum')
            ->postJson($this->url($project).'/estimate', $this->companyPayload())
            ->assertForbidden();

        $store = Route::getRoutes()->getByName('api.v1.story.projects.quick-start.store');
        $estimate = Route::getRoutes()->getByName('api.v1.story.projects.quick-start.estimate');
        $this->assertNotNull($store);
        $this->assertNotNull($estimate);
        $this->assertContains('throttle:generation', $store->gatherMiddleware());
        $this->assertContains('throttle:60,1', $estimate->gatherMiddleware());
    }

    public function test_reel_writes_vertical_aspect_on_the_bible_and_style(): void
    {
        $this->fakePlan(40);
        [$user, $project] = $this->ownerProject();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project), [
                'template' => 'reel',
                'topic' => 'One idea, said fast',
                'duration_seconds' => 40,
                'idempotency_key' => 'reel-1',
            ])
            ->assertOk()
            ->assertJsonPath('data.bible.aspect_ratio', '9:16')
            ->assertJsonPath('data.style.aspect_ratio', '9:16')
            ->assertJsonPath('data.style.visual_style', 'Bold social video');

        $this->assertSame('9:16', StoryBible::query()->sole()->aspect_ratio);
        $this->assertSame('9:16', StoryStyleBible::query()->sole()->aspect_ratio);
        $this->assertSame('Bold social video', StoryStyleBible::query()->sole()->visual_style);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function companyPayload(array $overrides = []): array
    {
        return array_replace([
            'template' => 'company_journey',
            'topic' => 'How Hn9 began',
            'duration_seconds' => 180,
            'fields' => ['company_name' => 'Hn9'],
        ], $overrides);
    }

    private function url(Project $project): string
    {
        return '/api/v1/story/projects/'.$project->uuid.'/quick-start';
    }

    /**
     * @return array{0: User, 1: Project}
     */
    private function ownerProject(): array
    {
        $user = User::factory()->create();

        return [$user, Project::factory()->for($user)->create()];
    }

    private function fakePlan(int $seconds, ?string $json = null): void
    {
        $this->bootOpenAiTextRuntime();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'model' => 'configured-openai-model',
                'output_text' => $json ?? $this->validPlanJson($seconds),
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30],
            ]),
        ]);
    }

    /**
     * @param  list<string>  $names
     */
    private function namedPlan(int $totalSeconds, array $names): string
    {
        $json = json_decode($this->validPlanJson($totalSeconds), true, 512, JSON_THROW_ON_ERROR);
        $json['scenes'][0]['characters'] = $names;

        return json_encode($json, JSON_THROW_ON_ERROR);
    }

    private function validPlanJson(int $totalSeconds): string
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
                'location' => 'Studio',
                'dialogue' => [],
                'narration' => 'The story moves forward.',
                'visual_prompt' => 'A quiet studio',
                'motion_prompt' => 'Slow push-in',
                'audio_direction' => 'Soft room tone',
                'continuity' => [
                    'previous_scene' => '',
                    'next_scene' => 'continues',
                    'character_state' => 'steady',
                    'environment_state' => 'studio',
                ],
            ];
        }

        return json_encode([
            'title' => 'Hn9 Journey',
            'logline' => 'A company grows.',
            'master_story' => 'The company starts, grows and looks ahead.',
            'total_duration_seconds' => $totalSeconds,
            'scene_duration_target_seconds' => 30,
            'remainder_strategy' => $calc['remainder_strategy'],
            'scenes' => $scenes,
        ], JSON_THROW_ON_ERROR);
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

    private function assertNothingProduced(): void
    {
        $this->assertSame(0, StoryProductionPlan::query()->count());
        $this->assertSame(0, StoryProductionUnit::query()->count());
        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
        $this->assertSame(0, StoryCharacterReference::query()->count());
        $this->assertSame(0, StoryStyleReference::query()->count());
        $this->assertSame(0, StorySceneAudio::query()->count());
        $this->assertSame(0, StoryFinalRender::query()->count());
        $this->assertSame(0, StoryReel::query()->count());
        $this->assertSame(0, StoryScene::query()->count());
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'bibles' => StoryBible::query()->count(),
            'styles' => StoryStyleBible::query()->count(),
            'characters' => StoryCharacter::query()->count(),
            'plans' => StoryPlan::query()->count(),
            'versions' => StoryPlanVersion::query()->count(),
        ];
    }
}
