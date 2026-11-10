<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Services\StoryProductionUnitGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\Support\ContractStoryVideoAdapter;
use Tests\TestCase;

final class StoryUnitRetryTest extends TestCase
{
    use BuildsProductionPlanFixtures;
    use RefreshDatabase;

    private ContractStoryVideoAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $video = config('story_cost.video');
        $video['video.contract'] = ['contract-model' => 5];
        config(['story_cost.video' => $video]);
        $this->adapter = new ContractStoryVideoAdapter;
        app(StoryCapabilityRouterInterface::class)->register($this->adapter);
    }

    public function test_a_failed_attempt_is_not_returned_as_a_new_generation(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $this->generate($f, $plan, $unit);
        $failed = $this->failLatest();
        $before = StoryVideoGenerationJob::query()->count();

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', ['capability' => 'text_to_video'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_unit_generation_failed')
            ->assertJsonPath('context.job_uuid', $failed->uuid)
            ->assertJsonPath('context.retry_available', true);

        $this->assertSame($before, StoryVideoGenerationJob::query()->count());

        $completed = $this->generate($f, $plan, $unit, 'clip-2');
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', ['capability' => 'text_to_video', 'intent' => 'clip-2'])
            ->assertOk()
            ->assertJsonPath('data.generation.id', $completed);
        $this->assertSame($before + 1, StoryVideoGenerationJob::query()->count());
    }

    public function test_retry_creates_one_new_attempt_and_a_later_failure_gets_another_intent(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $this->generate($f, $plan, $unit);
        $failed = $this->failLatest();
        $submits = $this->adapter->submits;

        $first = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/retry', ['capability' => 'text_to_video'])
            ->assertCreated()
            ->json('data');
        $this->assertSame(2, $first['attempt_number']);
        $this->assertSame(1, $first['retries_used']);
        $this->assertNotSame($failed->uuid, $first['generation']['id']);
        $retry = StoryVideoGenerationJob::query()->where('uuid', $first['generation']['id'])->first();
        $this->assertSame(StoryProductionUnitGenerationService::retryIntent($failed), $this->intentOf($retry));
        $this->assertSame($failed->uuid, $retry->provider_metadata['retry_of']['job_uuid']);
        $this->assertSame($submits + 1, $this->adapter->submits);
        $this->assertSame(1, ActivityLog::query()->where('action', 'story.unit_generation.retry_requested')->count());

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/retry', ['capability' => 'text_to_video'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_unit_retry_not_allowed');

        $again = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', [
                'capability' => 'text_to_video',
                'intent' => StoryProductionUnitGenerationService::retryIntent($failed),
            ])
            ->assertOk()
            ->json('data');
        $this->assertSame($first['generation']['id'], $again['generation']['id']);
        $this->assertSame($submits + 1, $this->adapter->submits);
        $this->assertSame(2, StoryVideoGenerationJob::query()->count());

        $this->failLatest();
        $third = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/retry', ['capability' => 'text_to_video'])
            ->assertCreated()
            ->json('data');
        $this->assertSame(3, $third['attempt_number']);
        $this->assertNotSame($first['generation']['id'], $third['generation']['id']);
        $this->assertNotSame($this->intentOf($retry), $this->intentOf(StoryVideoGenerationJob::query()->where('uuid', $third['generation']['id'])->first()));
    }

    public function test_retry_uses_the_current_prompt_and_rejects_a_stale_or_unaffordable_preview(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $this->generate($f, $plan, $unit);
        $this->failLatest();
        $scene = $unit->planScene->scene;
        $scene->forceFill(['story' => 'A paper boat at dusk'])->save();

        $preview = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/retry/prompt-preview', ['capability' => 'text_to_video'])
            ->assertOk()
            ->json('data');
        $this->assertFalse($preview['idempotent_repeat']);
        $this->assertTrue($preview['retry']['allowed']);
        $this->assertSame(2, $preview['retry']['attempt_number_next']);
        $this->assertSame(5, $preview['retry']['retries_left']);
        $this->assertStringContainsString('A paper boat at dusk', $preview['prompt']['final']);
        $this->assertSame(1, StoryVideoGenerationJob::query()->count());
        Http::assertNothingSent();

        $scene->forceFill(['story' => 'The street is empty now'])->save();
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/retry', [
                'capability' => 'text_to_video',
                'preview_hash' => $preview['preview_hash'],
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_prompt_preview_stale');
        $this->assertSame(1, StoryVideoGenerationJob::query()->count());
        Http::assertNothingSent();

        $fresh = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/retry/prompt-preview', ['capability' => 'text_to_video'])
            ->assertOk()
            ->json('data');
        $f['workspace']->forceFill(['budget_amount' => 0.01, 'budget_currency' => 'INR'])->save();
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/retry', [
                'capability' => 'text_to_video',
                'preview_hash' => $fresh['preview_hash'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_budget_exceeded');
        $this->assertSame(1, StoryVideoGenerationJob::query()->count());
        Http::assertNothingSent();
    }

    public function test_retry_is_refused_when_it_is_not_allowed_and_attempts_stay_plain(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $completed = $this->generate($f, $plan, $unit);
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/retry', [])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_unit_retry_not_allowed');

        $this->failLatest('INVALID_INPUT', 'provider key=secret raw body');
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/retry', [])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_unit_retry_not_retryable');

        $this->failLatest();
        config(['story_retry.max_user_retries_per_unit' => 0]);
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/retry', [])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_unit_retry_limit');

        config(['story_retry.max_user_retries_per_unit' => 5]);
        $created = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/retry', ['capability' => 'text_to_video'])
            ->assertCreated()
            ->json('data.generation.id');
        StoryVideoGenerationJob::query()->where('uuid', $created)->update(['retry_count' => 2]);

        $history = $this->actingAs($f['user'], 'sanctum')
            ->getJson($this->base($f, $plan, $unit).'/attempts')
            ->assertOk()
            ->json('data');
        $this->assertSame($created, $history[0]['job_uuid']);
        $this->assertSame(2, $history[0]['attempt_number']);
        $this->assertSame('retry', $history[0]['intent_kind']);
        $this->assertSame(2, $history[0]['provider_retries']);
        $this->assertSame($completed, $history[1]['job_uuid']);
        $encoded = json_encode($history);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('secret', $encoded);

        $failed = StoryVideoGenerationJob::query()->where('uuid', $completed)->first();
        $key = 'production-unit:'.$unit->uuid.':'.StoryProductionUnitGenerationService::retryIntent($failed);
        $this->assertSame($key, StoryVideoGenerationJob::query()->where('uuid', $created)->value('idempotency_key'));
    }

    public function test_a_job_that_already_has_the_retry_key_is_returned(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $this->generate($f, $plan, $unit);
        $failed = $this->failLatest();
        $intent = StoryProductionUnitGenerationService::retryIntent($failed);
        $existing = StoryVideoGenerationJob::query()->create([
            'story_workspace_id' => $failed->story_workspace_id,
            'capability' => 'text_to_video',
            'provider_key' => 'video.contract',
            'model_key' => 'contract-model',
            'operation_id' => 'already-submitted',
            'status' => 'submitted',
            'idempotency_key' => 'production-unit:'.$unit->uuid.':'.$intent,
            'request_payload' => ['capability' => 'text_to_video', 'duration_seconds' => 10],
        ]);
        $submits = $this->adapter->submits;

        $again = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/retry', ['capability' => 'text_to_video'])
            ->assertOk()
            ->json('data');

        $this->assertSame($existing->uuid, $again['generation']['id']);
        $this->assertFalse($again['created']);
        $this->assertSame(2, StoryVideoGenerationJob::query()->count());
        $this->assertSame($submits, $this->adapter->submits);
        Http::assertNothingSent();
    }

    public function test_retry_routes_require_auth_and_are_throttled(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $this->postJson($this->base($f, $plan, $unit).'/retry', [])->assertUnauthorized();
        $this->postJson($this->base($f, $plan, $unit).'/retry/prompt-preview', [])->assertUnauthorized();
        $this->getJson($this->base($f, $plan, $unit).'/attempts')->assertUnauthorized();
        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')->postJson($this->base($f, $plan, $unit).'/retry', [])->assertForbidden();
        $this->assertContains('throttle:generation', Route::getRoutes()->getByName('api.v1.story.projects.production-plans.units.retry')->gatherMiddleware());
        $this->assertContains('throttle:60,1', Route::getRoutes()->getByName('api.v1.story.projects.production-plans.units.retry.prompt-preview')->gatherMiddleware());
        $this->assertContains('throttle:60,1', Route::getRoutes()->getByName('api.v1.story.projects.production-plans.units.attempts')->gatherMiddleware());
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function generate(array $f, StoryProductionPlan $plan, StoryProductionUnit $unit, string $intent = 'initial'): string
    {
        return (string) $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', [
                'capability' => 'text_to_video',
                'intent' => $intent,
            ])
            ->assertCreated()
            ->json('data.generation.id');
    }

    private function failLatest(string $code = 'UPSTREAM_ERROR', string $message = 'provider key=secret raw body'): StoryVideoGenerationJob
    {
        $job = StoryVideoGenerationJob::query()->orderByDesc('id')->first();
        $this->assertInstanceOf(StoryVideoGenerationJob::class, $job);
        $job->forceFill([
            'status' => 'failed',
            'error_code' => $code,
            'error_message' => $message,
            'failed_at' => now(),
        ])->save();

        return $job->fresh();
    }

    private function intentOf(?StoryVideoGenerationJob $job): string
    {
        $key = (string) $job?->idempotency_key;
        $parts = explode(':', $key);

        return (string) array_pop($parts);
    }

    /**
     * @return array{0: array<string, mixed>, 1: StoryProductionPlan, 2: StoryProductionUnit}
     */
    private function ready(): array
    {
        $f = $this->productionFixture([10]);
        $plan = app(StoryProductionPlanServiceInterface::class)->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid, $f['user'])['plan'];
        $plan->load('scenes.units.planScene.scene');
        $unit = $plan->scenes->sortBy('sequence')->first()->units->sortBy('sequence')->first();
        $unit->load('planScene.scene');

        return [$f, $plan, $unit];
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function base(array $f, StoryProductionPlan $plan, StoryProductionUnit $unit): string
    {
        return '/api/v1/story/projects/'.$f['project']->uuid.'/production-plans/'.$plan->uuid.'/units/'.$unit->uuid;
    }
}
