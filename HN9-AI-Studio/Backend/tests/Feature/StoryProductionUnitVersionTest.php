<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Story\Contracts\StoryCapabilityRouterInterface;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Contracts\StoryProductionUnitVersionServiceInterface;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryProductionUnitVersion;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Services\StoryProductionUnitVersionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\Support\ContractStoryVideoAdapter;
use Tests\TestCase;

final class StoryProductionUnitVersionTest extends TestCase
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

    public function test_a_successful_attempt_becomes_version_a_and_a_second_becomes_version_b(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];
        $duration = $unit->duration_seconds;
        $start = $unit->start_second;

        $first = $this->finish($f, $plan, $unit);
        $second = $this->finish($f, $plan, $unit, 'again');

        $list = $this->actingAs($f['user'], 'sanctum')->getJson($this->base($f, $plan, $unit).'/versions')->assertOk()->json('data');
        $this->assertNull($list['message']);
        $this->assertSame(['A', 'B'], array_column($list['versions'], 'version'));
        $this->assertSame(['Version A', 'Version B'], array_column($list['versions'], 'label'));
        $this->assertSame([$first, $second], array_column($list['versions'], 'id'));
        $this->assertSame([false, false], array_column($list['versions'], 'selected'));
        $this->assertSame('pending_review', $list['versions'][0]['status']);
        $this->assertSame('Ready for review', $list['versions'][0]['status_label']);
        $this->assertSame(10, $list['versions'][0]['duration_seconds']);
        $this->assertTrue($list['versions'][0]['preview_available']);
        $unit->refresh();
        $this->assertSame($duration, $unit->duration_seconds);
        $this->assertSame($start, $unit->start_second);
        $this->assertSame(1, StoryProductionUnit::query()->count());
        $this->assertSame(2, StoryProductionUnitVersion::query()->count());
        $this->assertSame(
            StoryVideoGenerationJob::query()->where('idempotency_key', 'production-unit:'.$unit->uuid.':initial')->value('id'),
            StoryProductionUnitVersion::query()->where('uuid', $first)->value('story_video_generation_job_id'),
        );
    }

    public function test_failed_cancelled_and_repeated_attempts_do_not_create_versions(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];
        $this->adapter->permanentFailure = true;
        $failed = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', ['capability' => 'text_to_video'])
            ->json('data.generation.id');
        $this->actingAs($f['user'], 'sanctum')->getJson($this->base($f, $plan, $unit).'/generations/'.$failed);
        $this->adapter->permanentFailure = false;

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $unit).'/generate', ['capability' => 'text_to_video', 'intent' => 'stop'])
            ->assertCreated();
        $stopped = StoryVideoGenerationJob::query()->where('idempotency_key', 'production-unit:'.$unit->uuid.':stop')->firstOrFail();
        $this->actingAs($f['user'], 'sanctum')->postJson($this->base($f, $plan, $unit).'/generations/'.$stopped->uuid.'/cancel')->assertOk();

        $id = $this->finish($f, $plan, $unit, 'kept');
        $this->actingAs($f['user'], 'sanctum')->getJson($this->base($f, $plan, $unit).'/generations/'.$this->jobId($unit, 'kept'));

        $versions = $this->actingAs($f['user'], 'sanctum')->getJson($this->base($f, $plan, $unit).'/versions')->json('data.versions');
        $this->assertSame([$id], array_column($versions, 'id'));
        $this->assertSame(1, StoryProductionUnitVersion::query()->count());
    }

    public function test_a_version_number_clash_is_retried_without_a_duplicate(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];
        $tries = 0;
        StoryProductionUnitVersion::creating(function () use (&$tries): void {
            $tries++;
            if ($tries === 1) {
                throw new UniqueConstraintViolationException('sqlite', 'insert', [], new \RuntimeException('UNIQUE constraint failed'));
            }
        });

        $id = $this->finish($f, $plan, $unit);

        $this->assertGreaterThan(1, $tries);
        $this->assertSame([$id], StoryProductionUnitVersion::query()->pluck('uuid')->all());
        $this->assertSame(1, StoryProductionUnitVersion::query()->value('version_number'));
    }

    public function test_review_selection_and_rework_keep_every_version(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];
        $first = $this->finish($f, $plan, $unit);
        $second = $this->finish($f, $plan, $unit, 'second');
        $url = $this->base($f, $plan, $unit);

        $this->actingAs($f['user'], 'sanctum')->postJson($url.'/versions/'.$first.'/select')->assertStatus(422);
        $this->actingAs($f['user'], 'sanctum')->postJson($url.'/versions/'.$first.'/approve', [
            'job_id' => 'other-job',
            'output_asset_id' => 'other-file',
            'provider' => 'video.luma',
        ])->assertOk()->assertJsonPath('data.version.approved', true)->assertJsonPath('data.version.selected', false);
        $this->actingAs($f['user'], 'sanctum')->postJson($url.'/versions/'.$first.'/approve')->assertOk()->assertJsonPath('data.changed', false);
        $this->actingAs($f['user'], 'sanctum')->postJson($url.'/versions/'.$second.'/approve')->assertOk();
        $this->actingAs($f['user'], 'sanctum')->postJson($url.'/versions/'.$first.'/select')->assertOk()->assertJsonPath('data.version.selected', true);
        $this->actingAs($f['user'], 'sanctum')->postJson($url.'/versions/'.$first.'/select')->assertOk()->assertJsonPath('data.changed', false);
        $this->actingAs($f['user'], 'sanctum')->postJson($url.'/versions/'.$second.'/select')->assertOk();

        $unit->refresh();
        $this->assertSame($second, $unit->selectedVersion?->uuid);
        $this->assertSame(1, StoryProductionUnit::query()->whereNotNull('selected_version_id')->count());
        $kept = StoryProductionUnitVersion::query()->where('uuid', $first)->firstOrFail();
        $this->assertTrue($kept->isApproved());
        $this->assertNotSame($unit->selected_version_id, $kept->id);

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($url.'/versions/'.$first.'/request-changes', ['comment' => 'Make the harbor quieter'])
            ->assertStatus(422);
        $third = $this->finish($f, $plan, $unit, 'third');
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($url.'/versions/'.$third.'/request-changes', ['comment' => 'Make the harbor quieter'])
            ->assertOk()
            ->assertJsonPath('data.version.status', 'needs_rework');
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($url.'/versions/'.$third.'/request-changes', ['comment' => 'Make the harbor quieter'])
            ->assertOk()
            ->assertJsonPath('data.changed', false);

        $this->assertSame(['A', 'B', 'C'], StoryProductionUnitVersion::query()->orderBy('version_number')->get()->map(
            static fn (StoryProductionUnitVersion $version): string => app(StoryProductionUnitVersionServiceInterface::class)->show($f['project'], $plan->uuid, $unit->uuid, $version->uuid)['version'],
        )->all());
        $this->assertSame(1, ActivityLog::query()->where('action', StoryProductionUnitVersionService::EVENT_APPROVED)->where('subject_id', $kept->id)->count());
        $this->assertSame(1, ActivityLog::query()->where('action', StoryProductionUnitVersionService::EVENT_CHANGES_REQUESTED)->count());
        $history = $this->actingAs($f['user'], 'sanctum')->getJson('/api/v1/story/projects/'.$f['project']->uuid.'/history')->json('data');
        $events = array_values(array_filter($history, static fn (array $item): bool => ($item['kind'] ?? null) === 'unit_version'));
        $this->assertNotEmpty($events);
        $this->assertContains('Version A', array_column($events, 'version_label'));
        $this->assertSame([], array_filter(array_column($events, 'operation_id')));
        $this->assertSame([], array_filter(array_column($events, 'provider_key')));
    }

    public function test_continuity_uses_the_selected_version_only(): void
    {
        [$f, $plan] = $this->planned([17]);
        $units = $this->units($plan);
        $first = $this->finish($f, $plan, $units[0]);
        $second = $this->finish($f, $plan, $units[0], 'alt');
        $url = $this->base($f, $plan, $units[0]);
        $this->actingAs($f['user'], 'sanctum')->postJson($url.'/versions/'.$first.'/approve')->assertOk();
        $this->actingAs($f['user'], 'sanctum')->postJson($url.'/versions/'.$second.'/approve')->assertOk();
        $this->actingAs($f['user'], 'sanctum')->postJson($url.'/versions/'.$second.'/select')->assertOk();

        $ahead = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $units[1]).'/generate', ['capability' => 'text_to_video', 'intent' => 'next'])
            ->json('data.generation.id');
        $context = StoryVideoGenerationJob::query()->where('uuid', $ahead)->firstOrFail()->request_payload['metadata']['context']['continuity']['previous_unit'];
        $selectedPath = StoryProductionUnitVersion::query()->where('uuid', $second)->value('path');
        $otherPath = StoryProductionUnitVersion::query()->where('uuid', $first)->value('path');

        $this->assertSame($second, $context['version_id']);
        $this->assertSame($selectedPath, $context['output']['path']);
        $this->assertNotSame($otherPath, $context['output']['path']);
        $source = app(StoryProductionUnitVersionServiceInterface::class)->assemblySource($units[0]->fresh());
        $this->assertSame($second, $source['version_id']);
        $this->assertTrue($source['approved']);
        $this->assertSame(10, $source['duration_seconds']);
    }

    public function test_exact_lengths_and_a_forty_seven_second_scene_keep_their_units(): void
    {
        [$f, $plan] = $this->planned([5, 7, 10, 47]);
        $units = $this->units($plan);
        $this->assertSame([5, 7, 10, 10, 10, 10, 10, 7], array_map(static fn (StoryProductionUnit $unit): int => $unit->duration_seconds, $units));

        foreach ([0, 1, 2, 7] as $index) {
            $version = $this->finish($f, $plan, $units[$index], 'length-'.$index);
            $shown = $this->actingAs($f['user'], 'sanctum')
                ->getJson($this->base($f, $plan, $units[$index]).'/versions/'.$version)
                ->assertOk()
                ->json('data.version');
            $this->assertSame($units[$index]->duration_seconds, $shown['duration_seconds']);
            $this->assertEqualsWithDelta((float) $units[$index]->duration_seconds, (float) $shown['output_duration_seconds'], 0.5);
        }
        $this->assertSame(8, StoryProductionUnit::query()->count());
    }

    public function test_missing_media_cannot_be_selected_and_foreign_access_is_hidden(): void
    {
        [$f, $plan] = $this->planned([10]);
        $unit = $this->units($plan)[0];
        $this->postJson($this->base($f, $plan, $unit).'/versions/'.Str::uuid().'/approve')->assertUnauthorized();
        $version = $this->finish($f, $plan, $unit);
        $row = StoryProductionUnitVersion::query()->where('uuid', $version)->firstOrFail();
        Storage::disk('videos')->delete($row->path);
        $this->actingAs($f['user'], 'sanctum')->postJson($this->base($f, $plan, $unit).'/versions/'.$version.'/approve')->assertStatus(422);
        $this->actingAs($f['user'], 'sanctum')->getJson($this->base($f, $plan, $unit).'/versions')->assertJsonPath('data.versions.0.preview_available', false);

        $this->actingAs(User::factory()->create(), 'sanctum')->postJson($this->base($f, $plan, $unit).'/versions/'.$version.'/select')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create(), 'sanctum')->getJson($this->base($f, $plan, $unit).'/versions')->assertOk();

        [$otherF, $otherPlan] = $this->planned([10]);
        $foreign = $this->units($otherPlan)[0];
        $this->actingAs($f['user'], 'sanctum')->getJson($this->base($f, $plan, $foreign).'/versions')->assertNotFound();
        $this->actingAs($f['user'], 'sanctum')->getJson($this->base($f, $otherPlan, $unit).'/versions/'.$version)->assertNotFound();
        $this->actingAs($f['user'], 'sanctum')->getJson($this->base($otherF, $otherPlan, $unit).'/versions')->assertForbidden();
        $this->actingAs($f['user'], 'sanctum')
            ->getJson('/api/v1/story/projects/'.$f['project']->uuid.'/production-plans/'.Str::uuid().'/units/'.$unit->uuid.'/versions')
            ->assertNotFound();
    }

    public function test_an_empty_unit_explains_why_there_is_no_version(): void
    {
        [$f, $plan] = $this->planned([10, 10]);
        $units = $this->units($plan);

        $this->actingAs($f['user'], 'sanctum')
            ->getJson($this->base($f, $plan, $units[0]).'/versions')
            ->assertOk()
            ->assertJsonPath('data.message', 'No video versions yet.')
            ->assertJsonPath('data.versions', []);

        $this->adapter->completion = 'poll';
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->base($f, $plan, $units[1]).'/generate', ['capability' => 'text_to_video'])
            ->assertCreated();
        $this->actingAs($f['user'], 'sanctum')
            ->getJson($this->base($f, $plan, $units[1]).'/versions')
            ->assertJsonPath('data.message', 'Your video is still being generated.');
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
    private function base(array $f, StoryProductionPlan $plan, StoryProductionUnit $unit): string
    {
        return '/api/v1/story/projects/'.$f['project']->uuid.'/production-plans/'.$plan->uuid.'/units/'.$unit->uuid;
    }

    private function jobId(StoryProductionUnit $unit, string $intent): string
    {
        return (string) StoryVideoGenerationJob::query()
            ->where('idempotency_key', 'production-unit:'.$unit->uuid.':'.$intent)
            ->value('uuid');
    }
}
