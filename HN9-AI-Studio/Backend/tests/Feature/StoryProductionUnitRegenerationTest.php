<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryProductionUnitVersion;
use App\Story\Models\StoryVideoGenerationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\Support\ContractStoryVideoAdapter;
use Tests\TestCase;

final class StoryProductionUnitRegenerationTest extends TestCase
{
    use BuildsProductionPlanFixtures, RefreshDatabase;

    private ContractStoryVideoAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake('videos');
        Storage::fake('images');
        $this->adapter = new ContractStoryVideoAdapter;
        $this->adapter->completion = 'sync';
        app(StoryCapabilityRouterInterface::class)->register($this->adapter);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_a_thirty_second_scene_is_three_ten_second_units(): void
    {
        [, $plan] = $this->planned([30]);
        $units = $this->units($plan);

        $this->assertSame([10, 10, 10], array_map(static fn (StoryProductionUnit $unit): int => $unit->duration_seconds, $units));
        $this->assertSame([0, 10, 20], array_map(static fn (StoryProductionUnit $unit): int => $unit->start_second, $units));
        $this->assertSame(3, StoryProductionUnit::query()->count());
    }

    public function test_a_forty_seven_second_scene_keeps_a_seven_second_remainder(): void
    {
        [, $plan] = $this->planned([47]);
        $units = $this->units($plan);

        $this->assertSame([10, 10, 10, 10, 7], array_map(static fn (StoryProductionUnit $unit): int => $unit->duration_seconds, $units));
        $this->assertSame(5, StoryProductionUnit::query()->count());
    }

    public function test_regenerating_unit_two_does_not_create_jobs_for_the_other_units(): void
    {
        [$f, $plan] = $this->planned([30]);
        $units = $this->units($plan);

        $first = $this->finish($f, $plan, $units[0], 'u1');
        $second = $this->finish($f, $plan, $units[1], 'u2a');
        $third = $this->finish($f, $plan, $units[2], 'u3');
        $this->approveAndSelect($f, $plan, $units[0], $first);
        $this->approveAndSelect($f, $plan, $units[1], $second);
        $this->approveAndSelect($f, $plan, $units[2], $third);

        $before = StoryVideoGenerationJob::query()->count();
        $this->assertSame(3, $before);
        $this->adapter->submits = 0;

        $again = $this->finish($f, $plan, $units[1], 'u2b');

        $this->assertSame(1, $this->adapter->submits);
        $this->assertSame(4, StoryVideoGenerationJob::query()->count());
        $this->assertSame(1, StoryVideoGenerationJob::query()->where('story_production_unit_id', $units[0]->id)->count());
        $this->assertSame(2, StoryVideoGenerationJob::query()->where('story_production_unit_id', $units[1]->id)->count());
        $this->assertSame(1, StoryVideoGenerationJob::query()->where('story_production_unit_id', $units[2]->id)->count());
        $this->assertSame(1, StoryProductionPlan::query()->count());
        $this->assertSame(3, StoryProductionUnit::query()->count());

        $list = $this->actingAs($f['user'], 'sanctum')->getJson($this->base($f, $plan, $units[1]).'/versions')->json('data.versions');
        $this->assertSame([$second, $again], array_column($list, 'id'));
        $this->assertSame([true, false], array_column($list, 'approved'));
        $this->assertSame([true, false], array_column($list, 'selected'));
        $this->assertSame('pending_review', $list[1]['status']);

        $this->assertSame($first, $this->selectedId($units[0]));
        $this->assertSame($second, $this->selectedId($units[1]));
        $this->assertSame($third, $this->selectedId($units[2]));
        $this->actingAs($f['user'], 'sanctum')->getJson($this->base($f, $plan, $units[0]).'/versions')->assertJsonCount(1, 'data.versions');
        $this->actingAs($f['user'], 'sanctum')->getJson($this->base($f, $plan, $units[2]).'/versions')->assertJsonCount(1, 'data.versions');
    }

    public function test_the_same_unit_and_different_units_can_be_regenerated_many_times(): void
    {
        [$f, $plan] = $this->planned([30]);
        $units = $this->units($plan);

        $this->finish($f, $plan, $units[0], 'a');
        $this->finish($f, $plan, $units[0], 'b');
        $this->finish($f, $plan, $units[0], 'c');
        $this->finish($f, $plan, $units[2], 'd');
        $this->finish($f, $plan, $units[1], 'e');
        $this->finish($f, $plan, $units[2], 'f');
        $this->finish($f, $plan, $units[0], 'g');

        $this->assertSame(7, StoryVideoGenerationJob::query()->count());
        $this->assertSame(4, StoryVideoGenerationJob::query()->where('story_production_unit_id', $units[0]->id)->count());
        $this->assertSame(1, StoryVideoGenerationJob::query()->where('story_production_unit_id', $units[1]->id)->count());
        $this->assertSame(2, StoryVideoGenerationJob::query()->where('story_production_unit_id', $units[2]->id)->count());
        $this->assertSame(4, StoryProductionUnitVersion::query()->where('story_production_unit_id', $units[0]->id)->count());
        $this->assertSame(0, StoryProductionUnit::query()->whereNotNull('selected_version_id')->count());
    }

    public function test_a_version_from_another_unit_cannot_be_selected(): void
    {
        [$f, $plan] = $this->planned([20]);
        $units = $this->units($plan);
        $first = $this->finish($f, $plan, $units[0]);
        $second = $this->finish($f, $plan, $units[1]);
        $this->actingAs($f['user'], 'sanctum')->postJson($this->base($f, $plan, $units[0]).'/versions/'.$first.'/approve')->assertOk();
        $this->actingAs($f['user'], 'sanctum')->postJson($this->base($f, $plan, $units[1]).'/versions/'.$second.'/approve')->assertOk();

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $units[1]).'/versions/'.$first.'/select')
            ->assertNotFound();
        $this->assertNull($units[1]->fresh()->selected_version_id);
    }

    public function test_unauthorized_unit_regeneration_is_rejected(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];
        $url = $this->base($f, $plan, $unit).'/generate';

        $this->postJson($url, ['capability' => 'text_to_video'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum')->postJson($url, ['capability' => 'text_to_video'])->assertForbidden();

        [$other] = $this->planned([10]);
        $this->actingAs($other['user'], 'sanctum')->postJson($url, ['capability' => 'text_to_video'])->assertForbidden();
        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
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
     */
    private function finish(array $f, StoryProductionPlan $plan, StoryProductionUnit $unit, string $intent = 'initial'): string
    {
        $created = $this->actingAs($f['user'], 'sanctum')->postJson($this->base($f, $plan, $unit).'/generate', [
            'capability' => 'text_to_video',
            'intent' => $intent,
        ])->assertCreated();
        $jobId = $created->json('data.generation.id');
        $this->actingAs($f['user'], 'sanctum')->getJson($this->base($f, $plan, $unit).'/generations/'.$jobId)->assertOk();

        return (string) $this->actingAs($f['user'], 'sanctum')
            ->getJson($this->base($f, $plan, $unit).'/versions')
            ->json('data.versions.'.(StoryProductionUnitVersion::query()->where('story_production_unit_id', $unit->id)->count() - 1).'.id');
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function approveAndSelect(array $f, StoryProductionPlan $plan, StoryProductionUnit $unit, string $versionId): void
    {
        $url = $this->base($f, $plan, $unit);
        $this->actingAs($f['user'], 'sanctum')->postJson($url.'/versions/'.$versionId.'/approve')->assertOk();
        $this->actingAs($f['user'], 'sanctum')->postJson($url.'/versions/'.$versionId.'/select')->assertOk();
    }

    private function selectedId(StoryProductionUnit $unit): ?string
    {
        return $unit->fresh()->selectedVersion?->uuid;
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function base(array $f, StoryProductionPlan $plan, StoryProductionUnit $unit): string
    {
        return '/api/v1/story/projects/'.$f['project']->uuid.'/production-plans/'.$plan->uuid.'/units/'.$unit->uuid;
    }
}
