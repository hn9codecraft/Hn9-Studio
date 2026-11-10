<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CostSource;
use App\Models\User;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Enums\StoryCharacterReferenceSource;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryStyleBible;
use App\Story\Models\StoryStyleReference;
use App\Story\Models\StoryUsageLedgerEntry;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Models\StoryWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\Support\ContractStoryVideoAdapter;
use Tests\TestCase;

final class StoryCostBudgetTest extends TestCase
{
    use BuildsProductionPlanFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_unit_estimate_uses_runway_rate_and_configured_fx(): void
    {
        [$f, $plan, $unit] = $this->unitOf(10);
        config(['story_cost.fx.USD_INR' => 94]);

        $estimate = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->estimateUrl($f), [
                'kind' => 'unit_video',
                'plan_uuid' => $plan->uuid,
                'unit_uuid' => $unit->uuid,
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('video.runway', $estimate['provider_key']);
        $this->assertSame('gen4.5', $estimate['model_key']);
        $this->assertEqualsWithDelta(1.2, $estimate['amount_usd'], 0.0001);
        $this->assertEqualsWithDelta(112.8, $estimate['amount_display'], 0.001);
        $this->assertSame('INR', $estimate['display_currency']);
        $this->assertTrue($estimate['available']);
    }

    public function test_edit_audio_and_image_estimates_follow_the_rate_table(): void
    {
        $f = $this->productionFixture([8]);
        $scene = $f['scenes'][0];
        $version = StorySceneVersion::query()->create([
            'story_scene_id' => $scene->id,
            'version' => 1,
            'status' => 'approved',
        ]);

        $edit = $this->actingAs($f['user'], 'sanctum')->postJson($this->estimateUrl($f), [
            'kind' => 'scene_edit',
            'version_uuid' => $version->uuid,
        ])->assertOk()->json('data');
        $this->assertSame('aleph2', $edit['model_key']);
        $this->assertEqualsWithDelta(8, $edit['quantity'], 0.001);
        $this->assertEqualsWithDelta(2.24, $edit['amount_usd'], 0.0001);

        $audio = $this->actingAs($f['user'], 'sanctum')->postJson($this->estimateUrl($f), [
            'kind' => 'audio',
            'text' => str_repeat('a', 1000),
        ])->assertOk()->json('data');
        $this->assertSame('characters', $audio['units']);
        $this->assertEqualsWithDelta(1000, $audio['quantity'], 0.001);
        $this->assertEqualsWithDelta(0.08, $audio['amount_usd'], 0.0001);

        config(['story_cost.image.default' => null]);
        $image = $this->actingAs($f['user'], 'sanctum')->postJson($this->estimateUrl($f), [
            'kind' => 'reference_image',
            'count' => 1,
        ])->assertOk()->json('data');
        $this->assertFalse($image['available']);
        $this->assertNull($image['amount_usd']);
    }

    public function test_estimation_makes_no_http_calls(): void
    {
        [$f, $plan, $unit] = $this->unitOf(10);
        $this->actingAs($f['user'], 'sanctum')->postJson($this->estimateUrl($f), [
            'kind' => 'unit_video',
            'plan_uuid' => $plan->uuid,
            'unit_uuid' => $unit->uuid,
        ])->assertOk();
    }

    public function test_generation_is_allowed_when_no_budget_is_set(): void
    {
        [$f, $plan, $unit] = $this->unitOf(10);
        $this->registerContract();

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->unitUrl($f, $plan, $unit).'/generate', ['capability' => 'text_to_video'])
            ->assertCreated();
        $this->assertSame(1, StoryVideoGenerationJob::query()->count());
    }

    public function test_over_budget_actions_are_blocked_before_a_provider_call(): void
    {
        [$f, $plan, $unit] = $this->unitOf(10);
        $this->setBudget($f['workspace'], 0);
        $before = StoryVideoGenerationJob::query()->count();

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->unitUrl($f, $plan, $unit).'/generate', ['capability' => 'text_to_video'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_budget_exceeded');

        $scene = $f['scenes'][0];
        $this->actingAs($f['user'], 'sanctum')
            ->postJson("/api/v1/story/projects/{$f['project']->uuid}/reels/{$f['reel']->uuid}/scenes/{$scene->uuid}/regenerate", [])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_budget_exceeded');

        $this->actingAs($f['user'], 'sanctum')
            ->postJson("/api/v1/story/projects/{$f['project']->uuid}/reels/{$f['reel']->uuid}/scenes/{$scene->uuid}/audio", [
                'role' => 'narration',
                'prompt' => 'Hello from the studio',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_budget_exceeded');

        config(['story_cost.image.default' => 1.5]);
        $character = StoryCharacter::factory()->create([
            'story_workspace_id' => $f['workspace']->id,
            'name' => 'Mira',
        ]);
        $this->actingAs($f['user'], 'sanctum')
            ->postJson("/api/v1/story/projects/{$f['project']->uuid}/characters/{$character->uuid}/references/generate", [])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_budget_exceeded');

        $this->assertSame($before, StoryVideoGenerationJob::query()->count());
    }

    public function test_a_priced_job_stores_the_estimate_outside_the_ledger(): void
    {
        [$f, $plan, $unit] = $this->unitOf(10);
        $this->registerContract();
        $video = config('story_cost.video');
        $video['video.contract'] = ['contract-model' => 0.12];
        config(['story_cost.video' => $video]);
        $this->setBudget($f['workspace'], 10000);

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->unitUrl($f, $plan, $unit).'/generate', ['capability' => 'text_to_video'])
            ->assertCreated();

        $job = StoryVideoGenerationJob::query()->sole();
        $this->assertEqualsWithDelta(1.2, $job->provider_metadata['cost_estimate']['amount_usd'], 0.0001);
        $this->assertTrue($job->provider_metadata['cost_estimate']['available']);
        $this->assertNull(StoryUsageLedgerEntry::query()->where('story_video_generation_job_id', $job->id)->value('cost'));
    }

    public function test_an_idempotent_repeat_does_not_double_count_spend(): void
    {
        [$f, $plan, $unit] = $this->unitOf(10);
        $this->registerContract();
        $video = config('story_cost.video');
        $video['video.contract'] = ['contract-model' => 0.12];
        config(['story_cost.video' => $video]);
        $this->setBudget($f['workspace'], 10000);
        $url = $this->unitUrl($f, $plan, $unit).'/generate';

        $first = $this->actingAs($f['user'], 'sanctum')->postJson($url, ['capability' => 'text_to_video'])->assertCreated()->json('data.generation.id');
        $second = $this->actingAs($f['user'], 'sanctum')->postJson($url, ['capability' => 'text_to_video'])->assertOk()->json('data.generation.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, StoryVideoGenerationJob::query()->count());
        $summary = $this->actingAs($f['user'], 'sanctum')->getJson($this->summaryUrl($f))->assertOk()->json('data');
        $this->assertEqualsWithDelta(1.2, $summary['spent_estimated_usd'] + $summary['reserved_usd'], 0.0001);
    }

    public function test_summary_keeps_reported_and_estimated_spend_apart(): void
    {
        $f = $this->productionFixture([10]);
        $reported = StoryVideoGenerationJob::factory()->create([
            'story_workspace_id' => $f['workspace']->id,
            'capability' => StoryVideoCapability::TextToVideo->value,
            'provider_key' => 'video.runway',
            'status' => StoryVideoJobStatus::Completed->value,
            'operation_id' => 'op-1',
            'started_at' => now(),
            'provider_metadata' => ['cost_estimate' => ['amount_usd' => 9, 'available' => true]],
        ]);
        StoryUsageLedgerEntry::query()->where('story_video_generation_job_id', $reported->id)->update([
            'cost' => 2.5,
            'currency' => 'USD',
            'cost_source' => CostSource::ProviderReported->value,
        ]);
        StoryVideoGenerationJob::factory()->create([
            'story_workspace_id' => $f['workspace']->id,
            'capability' => StoryVideoCapability::TextToVideo->value,
            'provider_key' => 'video.luma',
            'status' => StoryVideoJobStatus::Completed->value,
            'operation_id' => 'op-2',
            'started_at' => now(),
            'provider_metadata' => ['cost_estimate' => ['amount_usd' => 1.5, 'available' => true]],
        ]);
        StoryVideoGenerationJob::factory()->create([
            'story_workspace_id' => $f['workspace']->id,
            'provider_key' => 'video.runway',
            'status' => StoryVideoJobStatus::Failed->value,
            'operation_id' => null,
            'started_at' => null,
            'provider_metadata' => ['cost_estimate' => ['amount_usd' => 4, 'available' => true]],
        ]);

        $summary = $this->actingAs($f['user'], 'sanctum')->getJson($this->summaryUrl($f))->assertOk()->json('data');

        $this->assertEqualsWithDelta(2.5, $summary['spent_reported_usd'], 0.0001);
        $this->assertEqualsWithDelta(1.5, $summary['spent_estimated_usd'], 0.0001);
        $this->assertEqualsWithDelta(4.0, $summary['spent_total_usd'], 0.0001);
        $providers = collect($summary['by_provider'])->keyBy('provider_key');
        $this->assertEqualsWithDelta(2.5, $providers['video.runway']['reported_usd'], 0.0001);
        $this->assertEqualsWithDelta(1.5, $providers['video.luma']['estimated_usd'], 0.0001);
    }

    public function test_summary_prices_generated_reference_pictures_and_skips_uploads(): void
    {
        $f = $this->productionFixture([10]);
        $character = StoryCharacter::factory()->create(['story_workspace_id' => $f['workspace']->id]);
        StoryCharacterReference::factory()->create([
            'story_character_id' => $character->id,
            'source' => StoryCharacterReferenceSource::Uploaded->value,
        ]);
        StoryCharacterReference::factory()->create([
            'story_character_id' => $character->id,
            'source' => StoryCharacterReferenceSource::Generated->value,
            'version' => 2,
        ]);
        $bible = StoryStyleBible::factory()->create(['story_workspace_id' => $f['workspace']->id]);
        StoryStyleReference::factory()->create([
            'story_style_bible_id' => $bible->id,
            'source' => 'uploaded',
        ]);

        config(['story_cost.image.default' => null]);
        $unpriced = $this->actingAs($f['user'], 'sanctum')->getJson($this->summaryUrl($f))->assertOk()->json('data');
        $this->assertEqualsWithDelta(0.0, $unpriced['spent_total_usd'], 0.0001);
        $this->assertSame(1, $unpriced['estimate_unavailable_jobs']);

        config(['story_cost.image.default' => 0.04]);
        $priced = $this->actingAs($f['user'], 'sanctum')->getJson($this->summaryUrl($f))->assertOk()->json('data');
        $this->assertEqualsWithDelta(0.04, $priced['spent_estimated_usd'], 0.0001);
        $this->assertEqualsWithDelta(0.04, $priced['spent_total_usd'], 0.0001);
        $this->assertSame(0, $priced['estimate_unavailable_jobs']);
        $images = collect($priced['by_provider'])->firstWhere('capability', 'reference_image');
        $this->assertSame(1, $images['jobs']);
        $this->assertEqualsWithDelta(0.04, $images['estimated_usd'], 0.0001);
    }

    public function test_budget_validation_and_owner_access(): void
    {
        $f = $this->productionFixture([10]);
        $other = User::factory()->create();
        $url = "/api/v1/story/projects/{$f['project']->uuid}/budget";

        $this->actingAs($f['user'], 'sanctum')->patchJson($url, ['budget_amount' => -1])->assertStatus(422);
        $this->actingAs($f['user'], 'sanctum')->patchJson($url, [
            'budget_amount' => 10,
            'budget_currency' => 'EUR',
        ])->assertStatus(422);
        $this->actingAs($f['user'], 'sanctum')->patchJson($url, [
            'budget_amount' => 25,
            'budget_currency' => 'INR',
        ])->assertOk()->assertJsonPath('data.budget.amount', 25);

        $this->actingAs($other, 'sanctum')->patchJson($url, ['budget_amount' => 1])->assertForbidden();
        $this->actingAs($other, 'sanctum')->postJson($this->estimateUrl($f), ['kind' => 'audio', 'text' => 'no'])->assertForbidden();
        $this->actingAs($other, 'sanctum')->getJson($this->summaryUrl($f))->assertForbidden();

        $foreign = $this->productionFixture([8]);
        $this->actingAs($f['user'], 'sanctum')->postJson($this->estimateUrl($f), [
            'kind' => 'scene_regenerate',
            'reel_uuid' => $foreign['reel']->uuid,
            'scene_uuid' => $foreign['scenes'][0]->uuid,
        ])->assertNotFound();
    }

    public function test_anonymous_estimate_is_rejected(): void
    {
        $f = $this->productionFixture([8]);
        $this->postJson($this->estimateUrl($f), ['kind' => 'audio', 'text' => 'no'])->assertUnauthorized();
    }

    public function test_estimate_route_is_rate_limited(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.story.projects.cost.estimate');
        $this->assertNotNull($route);
        $this->assertContains('throttle:60,1', $route->gatherMiddleware());
    }

    /**
     * @return array{0: array<string, mixed>, 1: \App\Story\Models\StoryProductionPlan, 2: \App\Story\Models\StoryProductionUnit}
     */
    private function unitOf(int $seconds): array
    {
        $f = $this->productionFixture([$seconds]);
        $plan = app(StoryProductionPlanServiceInterface::class)->createForVersion(
            $f['project'],
            $f['plan']->uuid,
            $f['version']->uuid,
            $f['user'],
        )['plan'];
        $plan->load('scenes.units');
        $unit = $plan->scenes->sortBy('sequence')->first()->units->sortBy('sequence')->first();

        return [$f, $plan, $unit];
    }

    private function registerContract(): void
    {
        app(StoryCapabilityRouterInterface::class)->register(new ContractStoryVideoAdapter);
    }

    private function setBudget(StoryWorkspace $workspace, float $amount): void
    {
        $workspace->forceFill([
            'budget_amount' => $amount,
            'budget_currency' => 'INR',
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function estimateUrl(array $f): string
    {
        return '/api/v1/story/projects/'.$f['project']->uuid.'/cost/estimate';
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function summaryUrl(array $f): string
    {
        return '/api/v1/story/projects/'.$f['project']->uuid.'/cost/summary';
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function unitUrl(array $f, mixed $plan, mixed $unit): string
    {
        return '/api/v1/story/projects/'.$f['project']->uuid.'/production-plans/'.$plan->uuid.'/units/'.$unit->uuid;
    }
}
