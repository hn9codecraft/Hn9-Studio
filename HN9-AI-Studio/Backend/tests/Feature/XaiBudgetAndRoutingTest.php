<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Contracts\StoryVideoEngineInterface;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Models\StoryWorkspace;
use App\Story\Services\StoryCostEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\Support\ConfiguresXai;
use Tests\TestCase;

final class XaiBudgetAndRoutingTest extends TestCase
{
    use BuildsProductionPlanFixtures;
    use ConfiguresXai;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_rates_follow_the_documented_table_and_can_be_overridden(): void
    {
        $videoRates = config('story_cost.video')['video.xai'];
        $imageRates = config('story_cost.image')['xai'];
        $this->assertEqualsWithDelta(0.02, $videoRates['grok-imagine-video-1.5-lite'], 0.0001);
        $this->assertEqualsWithDelta(0.08, $videoRates['grok-imagine-video-1.5'], 0.0001);
        $this->assertEqualsWithDelta(0.02, $imageRates['grok-imagine-image'], 0.0001);
        $this->assertEqualsWithDelta(0.04, $imageRates['grok-imagine-image-2.0'], 0.0001);
        $this->assertEqualsWithDelta(0.015, config('story_cost.audio')['audio.xai'], 0.0001);

        $video = config('story_cost.video');
        $video['video.xai']['grok-imagine-video-1.5-lite'] = 0.5;
        config(['story_cost.video' => $video]);
        $this->enableXai(['story_video.routing.preferred_order' => ['video.xai']]);
        $f = $this->productionFixture([10]);
        $estimate = app(StoryCostEstimator::class)->estimate($f['project'], 'video_generate', [
            'seconds' => 10,
        ]);

        $this->assertSame('video.xai', $estimate->providerKey);
        $this->assertEqualsWithDelta(5.0, $estimate->amountUsd, 0.0001);
    }

    public function test_a_tiny_budget_blocks_text_image_video_and_voice_before_any_request(): void
    {
        $this->enableXai([
            'ai.story_text_provider' => 'xai',
            'ai.story_image_provider' => 'xai',
            'story_voice.providers.en' => 'xai',
            'story_video.routing.preferred_order' => ['video.xai'],
        ]);
        Http::fake();
        $f = $this->productionFixture([10]);
        $f['workspace']->forceFill(['budget_amount' => 0.01, 'budget_currency' => 'INR'])->save();
        $user = $f['user'];
        $project = $f['project'];

        $planId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans", [
                'idea' => 'A budget check story that should not be generated.',
                'requested_duration_seconds' => 30,
            ])
            ->json('data.id');
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans/{$planId}/generate")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_budget_exceeded');

        $character = StoryCharacter::factory()->create([
            'story_workspace_id' => $f['workspace']->id,
            'name' => 'Mira',
        ]);
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/characters/{$character->uuid}/references/generate")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_budget_exceeded');

        [$plan, $unit] = $this->unit($f);
        $this->actingAs($user, 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', ['capability' => 'text_to_video'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_budget_exceeded');

        $scene = $f['scenes'][0];
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/reels/{$f['reel']->uuid}/scenes/{$scene->uuid}/audio", [
                'role' => 'narration',
                'prompt' => 'Hello from the studio',
                'language' => 'en',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_budget_exceeded');

        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
        Http::assertNothingSent();
    }

    public function test_enabling_xai_does_not_take_a_runway_clip(): void
    {
        $this->enableXai();
        config([
            'story_video.live_providers.runway.enabled' => true,
            'story_video.live_providers.runway.api_key' => 'runway-secret-test-key',
            'story_video.live_providers.runway.base_url' => 'https://api.dev.runwayml.com/v1',
            'story_video.live_providers.runway.model' => 'gen4.5',
            'story_video.live_providers.runway.key' => 'video.runway',
            'story_video.routing.preferred_order' => ['video.runway', 'video.luma', 'video.seedance'],
        ]);
        $this->app->forgetInstance(StoryCapabilityRouterInterface::class);
        $this->app->forgetInstance(StoryVideoEngineInterface::class);
        Http::fake(fn () => Http::response(['id' => 'rw-1', 'status' => 'PENDING']));

        $f = $this->productionFixture([10]);
        [$plan, $unit] = $this->unit($f);
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', ['capability' => 'text_to_video'])
            ->assertCreated();
        $this->assertSame('video.runway', StoryVideoGenerationJob::query()->sole()->provider_key);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.dev.runwayml.com'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.x.ai'));
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array{0: StoryProductionPlan, 1: StoryProductionUnit}
     */
    private function unit(array $f): array
    {
        $plan = app(StoryProductionPlanServiceInterface::class)->createForVersion(
            $f['project'],
            $f['plan']->uuid,
            $f['version']->uuid,
            $f['user'],
        )['plan'];
        $plan->load('scenes.units');
        $unit = $plan->scenes->sortBy('sequence')->first()->units->sortBy('sequence')->first();

        return [$plan, $unit];
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function base(array $f, StoryProductionPlan $plan, StoryProductionUnit $unit): string
    {
        return '/api/v1/story/projects/'.$f['project']->uuid.'/production-plans/'.$plan->uuid.'/units/'.$unit->uuid;
    }
}
