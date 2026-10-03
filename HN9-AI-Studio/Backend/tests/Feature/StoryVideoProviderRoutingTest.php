<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryVideoGenerationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\Support\ContractStoryVideoAdapter;
use Tests\TestCase;

final class StoryVideoProviderRoutingTest extends TestCase
{
    use BuildsProductionPlanFixtures, RefreshDatabase;

    private ContractStoryVideoAdapter $runway;

    private ContractStoryVideoAdapter $luma;

    private ContractStoryVideoAdapter $seedance;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake('videos');
        Storage::fake('images');
        $this->runway = new ContractStoryVideoAdapter(adapterKey: 'video.runway');
        $this->luma = new ContractStoryVideoAdapter(durations: [5, 10], adapterKey: 'video.luma');
        $this->luma->capabilities = [StoryVideoCapability::TextToVideo, StoryVideoCapability::ImageToVideo];
        $this->seedance = new ContractStoryVideoAdapter(adapterKey: 'video.seedance');
        $router = app(StoryCapabilityRouterInterface::class);
        $router->register($this->runway);
        $router->register($this->luma);
        $router->register($this->seedance);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_a_unit_is_sent_to_one_preferred_provider(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];

        $body = $this->generate($f, $plan, $unit, ['provider' => 'video.luma'])->assertCreated()->getContent();

        $this->assertSame(1, $this->runway->submits);
        $this->assertSame(0, $this->luma->submits);
        $this->assertSame(0, $this->seedance->submits);
        $this->assertSame([10], $this->runway->submittedDurations);
        $this->assertStringNotContainsString('video.runway', $body);
        $this->assertStringNotContainsString('video.luma', $body);
        $job = StoryVideoGenerationJob::query()->sole();
        $this->assertSame('video.runway', $job->provider_key);
        $this->assertSame('preferred_provider', $job->provider_metadata['routing_decision']['reason']);
        $this->assertSame(10, $job->provider_metadata['routing_decision']['requested_duration_seconds']);
    }

    public function test_the_same_intent_keeps_the_first_provider(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];

        $first = $this->generate($f, $plan, $unit)->assertCreated()->json('data.generation.id');
        $this->luma->available = true;
        $this->runway->available = false;
        $again = $this->generate($f, $plan, $unit)->assertOk()->json('data.generation.id');

        $this->assertSame($first, $again);
        $this->assertSame(1, $this->runway->submits);
        $this->assertSame(0, $this->luma->submits);
        $this->assertSame('video.runway', StoryVideoGenerationJob::query()->sole()->provider_key);
    }

    public function test_an_unavailable_provider_is_replaced_only_before_submission(): void
    {
        [$f, $plan] = $this->planned([10]);
        $this->runway->available = false;

        $this->generate($f, $plan, $this->units($plan)[0])->assertCreated();

        $this->assertSame(0, $this->runway->submits);
        $this->assertSame(1, $this->luma->submits);
        $this->assertSame('video.luma', StoryVideoGenerationJob::query()->sole()->provider_key);
        $this->assertSame('fallback_to_next_eligible', StoryVideoGenerationJob::query()->sole()->provider_metadata['routing_decision']['reason']);
    }

    public function test_a_rejection_before_acceptance_can_move_to_the_next_provider(): void
    {
        [$f, $plan] = $this->planned([10]);
        $this->runway->rejectBeforeAccept = true;

        $this->generate($f, $plan, $this->units($plan)[0])->assertCreated();

        $this->assertSame(0, $this->runway->submits);
        $this->assertSame(1, $this->luma->submits);
    }

    public function test_a_provider_that_already_accepted_the_job_is_not_replaced_when_it_fails(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];
        $this->runway->permanentFailure = true;
        $id = $this->generate($f, $plan, $unit)->json('data.generation.id');

        $this->actingAs($f['user'], 'sanctum')
            ->getJson($this->base($f, $plan, $unit).'/generations/'.$id)
            ->assertOk()
            ->assertJsonPath('data.generation.status', 'failed');

        $this->assertSame(1, $this->runway->submits);
        $this->assertSame(0, $this->luma->submits);
        $this->assertSame(0, $this->seedance->submits);
        $this->assertSame('video.runway', StoryVideoGenerationJob::query()->sole()->provider_key);
    }

    public function test_fallback_disabled_does_not_choose_another_provider(): void
    {
        [$f, $plan] = $this->planned([10]);
        config(['story_video.routing.fallback_enabled' => false]);
        $this->runway->available = false;

        $this->generate($f, $plan, $this->units($plan)[0])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VIDEO_CAPABILITY_NOT_AVAILABLE');

        $this->assertSame(0, $this->runway->submits);
        $this->assertSame(0, $this->luma->submits);
        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
    }

    public function test_a_seven_second_unit_is_not_rounded_up(): void
    {
        [$f, $plan] = $this->planned([17]);
        $remainder = $this->units($plan)[1];
        $this->assertSame(7, $remainder->duration_seconds);

        $this->generate($f, $plan, $remainder)->assertCreated();

        $this->assertSame([7], $this->runway->submittedDurations);
        $this->assertSame(0, $this->luma->submits);
    }

    public function test_image_mode_uses_one_provider_and_keeps_the_unit_length(): void
    {
        [$f, $plan] = $this->planned([10]);
        $reference = StoryCharacterReference::factory()->create([
            'story_character_id' => StoryCharacter::factory()->create(['story_workspace_id' => $f['workspace']->id])->id,
        ]);
        Storage::disk('images')->put($reference->path, 'image-bytes');

        $this->generate($f, $plan, $this->units($plan)[0], [
            'capability' => 'image_to_video',
            'inputs' => [['type' => 'image', 'asset_id' => $reference->uuid]],
        ])->assertCreated();

        $this->assertSame(1, $this->runway->submits);
        $this->assertSame([10], $this->runway->submittedDurations);
        $this->assertSame(0, $this->luma->submits);
        $this->assertSame(0, $this->seedance->submits);
    }

    public function test_three_requests_for_one_intent_submit_one_provider(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];

        $ids = [];
        foreach ([0, 1, 2] as $ignored) {
            $ids[] = $this->generate($f, $plan, $unit)->json('data.generation.id');
        }

        $this->assertSame([$ids[0], $ids[0], $ids[0]], $ids);
        $this->assertSame(1, $this->runway->submits);
        $this->assertSame(0, $this->luma->submits);
        $this->assertSame(0, $this->seedance->submits);
        $this->assertSame(1, StoryVideoGenerationJob::query()->count());
        $this->assertSame('video.runway', StoryVideoGenerationJob::query()->sole()->provider_key);
    }

    public function test_reference_mode_skips_providers_that_cannot_use_a_reference(): void
    {
        [$f, $plan] = $this->planned([10]);
        $this->runway->capabilities = [StoryVideoCapability::TextToVideo, StoryVideoCapability::ImageToVideo];
        $reference = StoryCharacterReference::factory()->create([
            'story_character_id' => StoryCharacter::factory()->create(['story_workspace_id' => $f['workspace']->id])->id,
        ]);
        Storage::disk('images')->put($reference->path, 'image-bytes');

        $this->generate($f, $plan, $this->units($plan)[0], [
            'capability' => 'reference_to_video',
            'inputs' => [['type' => 'reference_image', 'asset_id' => $reference->uuid]],
        ])->assertCreated();

        $this->assertSame(0, $this->runway->submits);
        $this->assertSame(0, $this->luma->submits);
        $this->assertSame(1, $this->seedance->submits);
    }

    /**
     * @param  list<int>  $durations
     * @return array{0: array<string, mixed>, 1: StoryProductionPlan}
     */
    private function planned(array $durations): array
    {
        $f = $this->productionFixture($durations);
        $plan = app(StoryProductionPlanServiceInterface::class)->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid, $f['user'])['plan'];
        $plan->load('scenes.units');

        return [$f, $plan];
    }

    /**
     * @return list<StoryProductionUnit>
     */
    private function units(StoryProductionPlan $plan): array
    {
        return $plan->scenes->sortBy('sequence')->flatMap(static fn ($scene) => $scene->units->sortBy('sequence'))->values()->all();
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $extra
     */
    private function generate(array $f, StoryProductionPlan $plan, StoryProductionUnit $unit, array $extra = []): TestResponse
    {
        return $this->actingAs($f['user'], 'sanctum')->postJson(
            $this->base($f, $plan, $unit).'/generate',
            array_merge(['capability' => 'text_to_video'], $extra),
        );
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function base(array $f, StoryProductionPlan $plan, StoryProductionUnit $unit): string
    {
        return '/api/v1/story/projects/'.$f['project']->uuid.'/production-plans/'.$plan->uuid.'/units/'.$unit->uuid;
    }
}
