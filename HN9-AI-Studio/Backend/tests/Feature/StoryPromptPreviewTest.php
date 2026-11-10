<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryProductionUnitVersion;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryUnitPromptEdit;
use App\Story\Models\StoryVideoGenerationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\Support\ContractStoryVideoAdapter;
use Tests\TestCase;

final class StoryPromptPreviewTest extends TestCase
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

    public function test_unit_preview_is_free_and_a_matching_hash_generates_once(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $before = $this->rows();
        $f['workspace']->forceFill(['budget_amount' => 0.01, 'budget_currency' => 'INR'])->save();

        $preview = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', ['capability' => 'text_to_video'])
            ->assertOk()
            ->json('data');

        $this->assertSame($before, $this->rows());
        Http::assertNothingSent();
        $this->assertNotSame('', $preview['preview_hash']);
        $this->assertSame(64, strlen($preview['preview_hash']));
        $this->assertArrayHasKey('estimate', $preview);
        $this->assertArrayHasKey('budget', $preview);
        $this->assertTrue($preview['routing']['matched']);
        $this->assertFalse($preview['idempotent_repeat']);
        $this->assertTrue($preview['budget']['would_exceed']);
        $f['workspace']->forceFill(['budget_amount' => null])->save();

        $created = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', [
                'capability' => 'text_to_video',
                'preview_hash' => $preview['preview_hash'],
            ])
            ->assertCreated();
        $this->assertSame('none', $created->json('data.generation.lock_status'));
        $this->assertSame(1, StoryVideoGenerationJob::query()->count());
        Http::assertNothingSent();

        $again = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', ['capability' => 'text_to_video', 'intent' => 'initial'])
            ->assertOk()
            ->json('data');
        $this->assertTrue($again['idempotent_repeat']);
    }

    public function test_a_stale_preview_is_rejected_before_the_budget_guard(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $f['workspace']->forceFill(['budget_amount' => 0.01, 'budget_currency' => 'INR'])->save();

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', [
                'capability' => 'text_to_video',
                'preview_hash' => str_repeat('ab', 32),
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_prompt_preview_stale');

        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
        Http::assertNothingSent();
    }

    public function test_saved_prompt_replaces_the_base_and_a_new_revision_does_not_copy_it(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $this->actingAs($f['user'], 'sanctum')
            ->putJson($this->base($f, $plan, $unit).'/prompt', ['prompt' => 'A paper boat at dusk'])
            ->assertOk();

        $preview = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', ['capability' => 'text_to_video'])
            ->assertOk()
            ->json('data');
        $this->assertSame('A paper boat at dusk', $preview['prompt']['base']);
        $this->assertSame('A paper boat at dusk', $preview['prompt']['final']);
        $this->assertTrue($preview['prompt']['edited']);

        $scene = $unit->planScene->scene;
        $scene->forceFill(['story' => 'A completely different story'])->save();
        $stale = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', ['capability' => 'text_to_video'])
            ->assertOk()
            ->json('data');
        $this->assertContains('edit_based_on_old_scene_text', $stale['warnings']);

        $this->actingAs($f['user'], 'sanctum')->putJson($this->base($f, $plan, $unit).'/prompt', ['prompt' => '   '])->assertStatus(422);
        $this->actingAs($f['user'], 'sanctum')->deleteJson($this->base($f, $plan, $unit).'/prompt')->assertOk();
        $reset = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', ['capability' => 'text_to_video'])
            ->assertOk()
            ->json('data');
        $this->assertFalse($reset['prompt']['edited']);
        $this->assertStringContainsString('A completely different story', $reset['prompt']['final']);

        $this->actingAs($f['user'], 'sanctum')
            ->putJson($this->base($f, $plan, $unit).'/prompt', ['prompt' => 'Keep this edit'])
            ->assertOk();
        $revised = app(StoryProductionPlanServiceInterface::class)->revise($f['project'], $plan->uuid, null, $f['user']);
        $newUnits = StoryProductionUnit::query()->whereIn('story_production_plan_scene_id', $revised['plan']->scenes()->pluck('id'))->pluck('id');
        $this->assertSame(0, StoryUnitPromptEdit::query()->whereIn('story_production_unit_id', $newUnits)->count());
        $this->assertSame(1, StoryUnitPromptEdit::query()->count());
    }

    public function test_preview_routes_require_auth_and_are_throttled(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $unit->load('planScene.scene');
        $regen = '/api/v1/story/projects/'.$f['project']->uuid.'/reels/'.$f['reel']->uuid.'/scenes/'.$unit->planScene->scene->uuid.'/regenerate/prompt-preview';
        $this->postJson($this->base($f, $plan, $unit).'/prompt-preview', ['capability' => 'text_to_video'])->assertUnauthorized();
        $this->postJson($regen, ['mode' => 'text'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', ['capability' => 'text_to_video'])
            ->assertForbidden();
        $this->actingAs(User::factory()->create(), 'sanctum')->postJson($regen, ['mode' => 'text'])->assertForbidden();
        $this->assertContains('throttle:60,1', Route::getRoutes()->getByName('api.v1.story.projects.production-plans.units.prompt-preview')->gatherMiddleware());
        $this->assertContains('throttle:30,1', Route::getRoutes()->getByName('api.v1.story.projects.production-plans.units.prompt.update')->gatherMiddleware());
        $this->assertContains('throttle:60,1', Route::getRoutes()->getByName('api.v1.story.projects.reels.scenes.regenerate.prompt-preview')->gatherMiddleware());
    }

    public function test_a_matching_hash_on_a_tiny_budget_is_rejected_by_the_guard(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $f['workspace']->forceFill(['budget_amount' => 0.01, 'budget_currency' => 'INR'])->save();

        $preview = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', ['capability' => 'text_to_video'])
            ->assertOk()
            ->json('data');
        $this->assertTrue($preview['budget']['would_exceed']);

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', [
                'capability' => 'text_to_video',
                'preview_hash' => $preview['preview_hash'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_budget_exceeded');

        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_changed_scene_makes_the_preview_stale_and_an_unmatched_provider_stays_ok(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $preview = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', ['capability' => 'text_to_video'])
            ->assertOk()
            ->json('data');

        $unit->planScene->scene->forceFill(['story' => 'The street is empty now'])->save();
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', [
                'capability' => 'text_to_video',
                'preview_hash' => $preview['preview_hash'],
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_prompt_preview_stale');
        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
        Http::assertNothingSent();

        $this->adapter->available = false;
        $open = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/prompt-preview', ['capability' => 'text_to_video'])
            ->assertOk()
            ->json('data');
        $this->assertFalse($open['routing']['matched']);
        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
    }

    /**
     * @return array{0: array<string, mixed>, 1: StoryProductionPlan, 2: StoryProductionUnit}
     */
    private function ready(): array
    {
        $f = $this->productionFixture([10]);
        $plan = app(StoryProductionPlanServiceInterface::class)->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid, $f['user'])['plan'];
        $plan->load('scenes.units');
        $unit = $plan->scenes->sortBy('sequence')->first()->units->sortBy('sequence')->first();

        return [$f, $plan, $unit];
    }

    /**
     * @return array<string, int>
     */
    private function rows(): array
    {
        return [
            'jobs' => StoryVideoGenerationJob::query()->count(),
            'versions' => StoryProductionUnitVersion::query()->count(),
            'scene_versions' => StorySceneVersion::query()->count(),
            'activity' => ActivityLog::query()->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function base(array $f, StoryProductionPlan $plan, StoryProductionUnit $unit): string
    {
        return '/api/v1/story/projects/'.$f['project']->uuid.'/production-plans/'.$plan->uuid.'/units/'.$unit->uuid;
    }
}
