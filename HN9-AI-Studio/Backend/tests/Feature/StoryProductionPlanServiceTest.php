<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Enums\StoryPlanStatus;
use App\Story\Enums\StoryPlanVersionStatus;
use App\Story\Enums\StoryReelStatus;
use App\Story\Enums\StorySceneStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionPlanScene;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneVersion;
use App\Story\Services\StoryProductionPlanService;
use App\Story\Support\StoryGenerationUnitCalculator;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\TestCase;

final class StoryProductionPlanServiceTest extends TestCase
{
    use BuildsProductionPlanFixtures, RefreshDatabase;

    private StoryProductionPlanServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->service = app(StoryProductionPlanServiceInterface::class);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    // ------------------------------------------------------------ creation --

    public function test_plan_splits_each_scene_into_ten_second_units_with_exact_offsets(): void
    {
        $f = $this->productionFixture([47, 10, 5]);

        $result = $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid, $f['user']);
        $plan = $result['plan'];

        $this->assertTrue($result['created']);
        $this->assertSame(1, $plan->revision);
        $this->assertTrue($plan->isCurrent());
        $this->assertSame($f['plan']->id, $plan->current_for_story_plan_id);
        $this->assertSame(StoryGenerationUnitCalculator::UNIT_SECONDS, $plan->unit_seconds);
        $this->assertSame(62, $plan->total_duration_seconds);
        $this->assertSame($f['version']->id, $plan->story_plan_version_id);
        $this->assertSame($f['reel']->id, $plan->story_reel_id);
        $this->assertSame($f['workspace']->id, $plan->story_workspace_id);
        $this->assertSame($f['user']->id, $plan->created_by);
        $this->assertNull($plan->previous_plan_id);

        $this->assertSame([
            [1, $f['scenes'][0]->id, 0, 47],
            [2, $f['scenes'][1]->id, 47, 10],
            [3, $f['scenes'][2]->id, 57, 5],
        ], $plan->scenes->map(static fn (StoryProductionPlanScene $s): array => [$s->sequence, $s->story_scene_id, $s->start_second, $s->duration_seconds])->all());

        $this->assertSame(
            [[1, 0, 10], [2, 10, 10], [3, 20, 10], [4, 30, 10], [5, 40, 7]],
            $this->unitLayout($plan->scenes[0]),
        );
        $this->assertSame([[1, 0, 10]], $this->unitLayout($plan->scenes[1]));
        $this->assertSame([[1, 0, 5]], $this->unitLayout($plan->scenes[2]));

        foreach ($plan->scenes as $scene) {
            $this->assertSame($scene->duration_seconds, (int) $scene->units->sum('duration_seconds'));
        }
        $this->assertSame(7, StoryProductionUnit::query()->count());

        $log = ActivityLog::query()->where('action', StoryProductionPlanService::EVENT_CREATED)->sole();
        $this->assertSame($plan->id, (int) $log->subject_id);
        $this->assertSame(7, $log->properties['unit_count']);
        $this->assertSame(3, $log->properties['scene_count']);
    }

    public function test_scene_length_is_not_limited_to_thirty_seconds(): void
    {
        $f = $this->productionFixture([120, 3600, 1]);

        $plan = $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid)['plan'];

        $this->assertSame([12, 360, 1], $plan->scenes->map(static fn (StoryProductionPlanScene $s): int => $s->units->count())->all());
        $this->assertSame(3721, $plan->total_duration_seconds);
        $this->assertSame([3600, 0, 1], [$plan->scenes[1]->units->last()->endSecond(), $plan->scenes[2]->units[0]->start_second, $plan->scenes[2]->units[0]->duration_seconds]);
    }

    public function test_same_scene_lengths_always_produce_the_same_layout(): void
    {
        $first = $this->productionFixture([31, 17, 60]);
        $second = $this->productionFixture([31, 17, 60]);

        $a = $this->service->createForVersion($first['project'], $first['plan']->uuid, $first['version']->uuid)['plan'];
        $b = $this->service->createForVersion($second['project'], $second['plan']->uuid, $second['version']->uuid)['plan'];

        $this->assertSame($this->planLayout($a), $this->planLayout($b));
    }

    public function test_manual_scenes_on_the_materialized_reel_are_planned_and_archived_ones_are_not(): void
    {
        $f = $this->productionFixture([10, 10]);
        $f['scenes'][1]->forceFill(['status' => StorySceneStatus::Archived->value])->save();
        $manual = StoryScene::factory()->create([
            'story_reel_id' => $f['reel']->id,
            'sequence' => 3,
            'duration_seconds' => 13,
            'status' => StorySceneStatus::Draft->value,
            'source_plan_version_id' => null,
        ]);

        $plan = $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid)['plan'];

        $this->assertSame([$f['scenes'][0]->id, $manual->id], $plan->scenes->pluck('story_scene_id')->all());
        $this->assertSame(23, $plan->total_duration_seconds);
    }

    public function test_the_latest_scene_version_is_recorded(): void
    {
        $f = $this->productionFixture([10]);
        $scene = $f['scenes'][0];
        StorySceneVersion::query()->create(['story_scene_id' => $scene->id, 'version' => 1, 'status' => 'approved']);
        $latest = StorySceneVersion::query()->create(['story_scene_id' => $scene->id, 'version' => 2, 'status' => 'pending_review']);

        $plan = $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid)['plan'];

        $this->assertSame($latest->id, $plan->scenes[0]->story_scene_version_id);
    }

    // ---------------------------------------------------------- validation --

    public function test_a_version_that_is_not_finished_is_rejected(): void
    {
        foreach ([StoryPlanVersionStatus::Generating, StoryPlanVersionStatus::Failed] as $status) {
            $f = $this->productionFixture([10]);
            $f['version']->forceFill(['status' => $status->value])->save();

            $this->assertRejected('story_production_source_not_ready', fn () => $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid));
        }
        $this->assertNothingWritten();
    }

    public function test_a_version_without_scenes_is_rejected(): void
    {
        $f = $this->productionFixture([10, 20]);
        StoryScene::query()->where('story_reel_id', $f['reel']->id)->update(['status' => StorySceneStatus::Archived->value]);

        $this->assertRejected('story_production_source_not_ready', fn () => $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid));
        $this->assertNothingWritten();
    }

    public function test_a_version_that_was_never_turned_into_scenes_is_rejected(): void
    {
        $f = $this->productionFixture([10]);
        $unmaterialized = $f['version']->replicate()->forceFill(['uuid' => (string) Str::uuid(), 'version' => 2]);
        $unmaterialized->save();

        $this->assertRejected('story_production_source_not_ready', fn () => $this->service->createForVersion($f['project'], $f['plan']->uuid, $unmaterialized->uuid));
        $this->assertNothingWritten();
    }

    public function test_an_archived_reel_is_rejected(): void
    {
        $f = $this->productionFixture([10]);
        $f['reel']->forceFill(['status' => StoryReelStatus::Archived->value])->save();

        $this->assertRejected('story_production_source_not_ready', fn () => $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid));
        $this->assertNothingWritten();
    }

    public function test_an_invalid_scene_length_rejects_the_whole_plan(): void
    {
        foreach ([0, 3601] as $seconds) {
            $f = $this->productionFixture([10, 20]);
            $f['scenes'][1]->forceFill(['duration_seconds' => $seconds])->save();

            $exception = $this->assertRejected('story_production_invalid_duration', fn () => $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid));
            $this->assertStringStartsWith('Scene 2:', $exception->getMessage());
        }
        $this->assertNothingWritten();
    }

    public function test_a_scene_from_another_story_version_is_rejected(): void
    {
        $f = $this->productionFixture([10, 10]);
        [$other] = $this->materializedVersion($f['workspace'], $f['plan'], 2, [10]);
        $f['scenes'][1]->forceFill(['source_plan_version_id' => $other->id])->save();

        $this->assertRejected('story_production_invalid_scene', fn () => $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid));
        $this->assertSame(0, StoryProductionPlan::query()->count());
    }

    public function test_identifiers_from_another_project_are_not_found(): void
    {
        $mine = $this->productionFixture([10]);
        $theirs = $this->productionFixture([10]);

        $this->assertRejected('story_not_found', fn () => $this->service->createForVersion($mine['project'], $theirs['plan']->uuid, $theirs['version']->uuid));
        $this->assertRejected('story_not_found', fn () => $this->service->createForVersion($mine['project'], $mine['plan']->uuid, $theirs['version']->uuid));
        $this->assertRejected('story_not_found', fn () => $this->service->createForVersion($mine['project'], (string) Str::uuid(), $mine['version']->uuid));

        $theirPlan = $this->service->createForVersion($theirs['project'], $theirs['plan']->uuid, $theirs['version']->uuid)['plan'];
        $this->assertRejected('story_not_found', fn () => $this->service->getForProject($mine['project'], $theirPlan->uuid));
        $this->assertRejected('story_not_found', fn () => $this->service->revise($mine['project'], $theirPlan->uuid));
        $this->assertRejected('story_not_found', fn () => $this->service->sceneForPlan($mine['project'], $theirPlan->uuid, $theirs['scenes'][0]->uuid));
        $this->assertRejected('story_not_found', fn () => $this->service->currentForStoryPlan($mine['project'], $theirs['plan']->uuid));
        $this->assertSame(1, StoryProductionPlan::query()->count());
    }

    // --------------------------------------------------------- atomicity --

    public function test_a_failure_while_writing_units_leaves_nothing_behind(): void
    {
        $f = $this->productionFixture([47, 10]);
        $this->failUnitInserts();
        Log::spy();

        $exception = $this->assertRejected('story_production_plan_failed', fn () => $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid));

        $this->assertSame('The production plan could not be created. Nothing was saved.', $exception->getMessage());
        Log::shouldHaveReceived('error')->once()->withArgs(static fn (string $message, array $context): bool => $message === 'Production plan write failed and was rolled back.'
            && $context['story_plan_version'] === $f['version']->uuid
            && str_contains($context['message'], 'disk full')
            && ! str_contains($context['message'], 'insert into'));
        $this->assertNothingWritten();
        $this->assertSame(0, ActivityLog::query()->where('action', StoryProductionPlanService::EVENT_CREATED)->count());
    }

    public function test_a_failed_revision_keeps_the_current_plan_current(): void
    {
        $f = $this->productionFixture([20]);
        $plan = $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid)['plan'];
        $this->failUnitInserts();

        $this->assertRejected('story_production_plan_failed', fn () => $this->service->revise($f['project'], $plan->uuid));

        $plan->refresh();
        $this->assertTrue($plan->isCurrent());
        $this->assertNull($plan->superseded_at);
        $this->assertSame(1, StoryProductionPlan::query()->count());
        $this->assertSame(2, StoryProductionUnit::query()->count());
    }

    // ------------------------------------------------- idempotency & races --

    public function test_repeating_creation_returns_the_same_plan_without_duplicates(): void
    {
        $f = $this->productionFixture([47, 10]);

        $first = $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid);
        $again = $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid);

        $this->assertTrue($first['created']);
        $this->assertFalse($again['created']);
        $this->assertSame($first['plan']->id, $again['plan']->id);
        $this->assertSame(1, StoryProductionPlan::query()->count());
        $this->assertSame(2, StoryProductionPlanScene::query()->count());
        $this->assertSame(6, StoryProductionUnit::query()->count());
        $this->assertSame(1, ActivityLog::query()->where('action', StoryProductionPlanService::EVENT_CREATED)->count());
    }

    public function test_a_concurrent_creation_that_wins_the_race_is_returned_instead_of_duplicated(): void
    {
        $f = $this->productionFixture([25]);
        $state = ['conflicted' => false, 'competitor' => null];

        // First insert is rejected as if another request had just taken the slot; when that
        // write rolls back, the "other request" commits its own plan for the same version.
        StoryProductionPlan::creating(function () use (&$state): void {
            if (! $state['conflicted']) {
                $state['conflicted'] = true;
                throw new UniqueConstraintViolationException('sqlite', 'insert into story_production_plans', [], new \RuntimeException('UNIQUE constraint failed'));
            }
        });
        Event::listen(TransactionRolledBack::class, function () use (&$state, $f): void {
            if ($state['competitor'] === null) {
                $state['competitor'] = false;
                $state['competitor'] = $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid)['plan'];
            }
        });

        $result = $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid);

        $this->assertTrue($state['conflicted']);
        $this->assertFalse($result['created']);
        $this->assertSame($state['competitor']->id, $result['plan']->id);
        $this->assertSame(1, StoryProductionPlan::query()->count());
        $this->assertSame(3, StoryProductionUnit::query()->count());
    }

    public function test_a_different_version_needs_an_explicit_revision(): void
    {
        $f = $this->productionFixture([10]);
        $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid);
        [$v2] = $this->materializedVersion($f['workspace'], $f['plan'], 2, [20]);

        $this->assertRejected('story_production_plan_exists', fn () => $this->service->createForVersion($f['project'], $f['plan']->uuid, $v2->uuid));
        $this->assertSame(1, StoryProductionPlan::query()->count());
    }

    // ------------------------------------------------- revisions & history --

    public function test_revisions_preserve_every_earlier_plan(): void
    {
        $f = $this->productionFixture([47]);
        $a = $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid, $f['user'])['plan'];
        $aLayout = $this->planLayout($a);

        [$v2, , $v2Scenes] = $this->materializedVersion($f['workspace'], $f['plan'], 2, [30, 15]);
        $b = $this->service->revise($f['project'], $a->uuid, $v2->uuid, $f['user'])['plan'];

        $v2Scenes[1]->forceFill(['duration_seconds' => 22])->save();
        $f['scenes'][0]->forceFill(['duration_seconds' => 5])->save();
        $c = $this->service->revise($f['project'], $b->uuid, null, $f['user'])['plan'];

        $a->refresh();
        $b->refresh();
        $this->assertSame([1, 2, 3], [$a->revision, $b->revision, $c->revision]);
        $this->assertSame([null, $a->id, $b->id], [$a->previous_plan_id, $b->previous_plan_id, $c->previous_plan_id]);
        $this->assertSame(['superseded', 'superseded', 'active'], [$a->status, $b->status, $c->status]);
        $this->assertNotNull($a->superseded_at);
        $this->assertNotNull($b->superseded_at);
        $this->assertSame([null, null, $f['plan']->id], [$a->current_for_story_plan_id, $b->current_for_story_plan_id, $c->current_for_story_plan_id]);
        $this->assertSame([$f['version']->id, $v2->id, $v2->id], [$a->story_plan_version_id, $b->story_plan_version_id, $c->story_plan_version_id]);

        $this->assertSame($aLayout, $this->planLayout($this->service->getForProject($f['project'], $a->uuid)));
        $this->assertSame(45, $b->total_duration_seconds);
        $this->assertSame(52, $c->total_duration_seconds);
        $this->assertSame([[1, 0, 10], [2, 10, 10], [3, 20, 2]], $this->unitLayout($c->scenes[1]));

        $this->assertSame($c->id, $this->service->currentForStoryPlan($f['project'], $f['plan']->uuid)?->id);
        $this->assertSame($b->uuid, $this->service->getForProject($f['project'], $a->uuid)->nextPlan?->uuid);
        $this->assertSame(2, ActivityLog::query()->where('action', StoryProductionPlanService::EVENT_REVISED)->count());
    }

    public function test_repeating_a_revision_returns_the_revision_already_made(): void
    {
        $f = $this->productionFixture([10]);
        $a = $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid)['plan'];

        $first = $this->service->revise($f['project'], $a->uuid);
        $again = $this->service->revise($f['project'], $a->uuid);

        $this->assertTrue($first['created']);
        $this->assertFalse($again['created']);
        $this->assertSame($first['plan']->id, $again['plan']->id);
        $this->assertSame(2, StoryProductionPlan::query()->count());
    }

    public function test_only_the_current_plan_can_be_revised_into_something_new(): void
    {
        $f = $this->productionFixture([10]);
        $a = $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid)['plan'];
        $this->service->revise($f['project'], $a->uuid);
        [$v2] = $this->materializedVersion($f['workspace'], $f['plan'], 2, [20]);

        $this->assertRejected('story_production_plan_not_current', fn () => $this->service->revise($f['project'], $a->uuid, $v2->uuid));
        $this->assertSame(2, StoryProductionPlan::query()->count());
    }

    public function test_a_revision_cannot_switch_to_another_story(): void
    {
        $f = $this->productionFixture([10]);
        $a = $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid)['plan'];
        $part2 = StoryPlan::factory()->create(['story_workspace_id' => $f['workspace']->id, 'status' => StoryPlanStatus::Completed->value]);
        [$part2Version] = $this->materializedVersion($f['workspace'], $part2, 1, [20]);

        $this->assertRejected('story_not_found', fn () => $this->service->revise($f['project'], $a->uuid, $part2Version->uuid));
        $this->assertTrue($a->refresh()->isCurrent());
    }

    public function test_a_second_story_in_the_same_project_gets_its_own_plan_history(): void
    {
        $f = $this->productionFixture([10]);
        $part1 = $this->service->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid)['plan'];
        $part2Story = StoryPlan::factory()->create(['story_workspace_id' => $f['workspace']->id, 'status' => StoryPlanStatus::Completed->value]);
        [$part2Version] = $this->materializedVersion($f['workspace'], $part2Story, 1, [33]);

        $part2 = $this->service->createForVersion($f['project'], $part2Story->uuid, $part2Version->uuid)['plan'];

        $this->assertSame([1, 1], [$part1->revision, $part2->revision]);
        $this->assertTrue($part1->refresh()->isCurrent());
        $this->assertTrue($part2->isCurrent());
        $this->assertSame(2, $this->service->listForProject($f['project'])->count());
    }

    // --------------------------------------------------------- performance --

    public function test_query_count_does_not_grow_with_the_number_of_units(): void
    {
        $small = $this->productionFixture([10]);
        $large = $this->productionFixture(array_fill(0, 20, 3600));

        $smallQueries = $this->countQueries(fn () => $this->service->createForVersion($small['project'], $small['plan']->uuid, $small['version']->uuid));
        $start = microtime(true);
        $largeQueries = $this->countQueries(fn () => $this->service->createForVersion($large['project'], $large['plan']->uuid, $large['version']->uuid));
        $elapsed = microtime(true) - $start;

        $this->assertSame(7200, StoryProductionUnit::query()->whereHas('planScene.plan', fn ($q) => $q->where('story_workspace_id', $large['workspace']->id))->count());
        // Only the bulk insert chunks scale: 7,200 units in batches of 500 add 14 statements.
        $this->assertLessThanOrEqual($smallQueries + 14, $largeQueries);
        $this->assertLessThan(15.0, $elapsed);
    }

    // ------------------------------------------------------------- helpers --

    /**
     * Makes the database reject unit rows after the plan and its scene rows were written.
     * MySQL trigger DDL would commit the test transaction, so other drivers fail from a query hook.
     */
    private function failUnitInserts(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER fail_unit_insert BEFORE INSERT ON story_production_units BEGIN SELECT RAISE(ABORT, 'disk full'); END;");

            return;
        }

        DB::listen(static function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'story_production_units')) {
                throw new QueryException($query->connectionName, $query->sql, $query->bindings, new \PDOException('disk full'));
            }
        });
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function assertRejected(string $code, callable $callback): StoryException
    {
        try {
            $callback();
        } catch (StoryException $exception) {
            $this->assertSame($code, $exception->errorCode(), $exception->getMessage());

            return $exception;
        }

        $this->fail("Expected {$code}.");
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, StoryProductionPlan::query()->count());
        $this->assertSame(0, StoryProductionPlanScene::query()->count());
        $this->assertSame(0, StoryProductionUnit::query()->count());
    }

    /**
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private function unitLayout(StoryProductionPlanScene $scene): array
    {
        return $scene->units->map(static fn (StoryProductionUnit $u): array => [$u->sequence, $u->start_second, $u->duration_seconds])->values()->all();
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function planLayout(StoryProductionPlan $plan): array
    {
        return $plan->scenes->map(fn (StoryProductionPlanScene $s): array => [$s->sequence, $s->start_second, $s->duration_seconds, $this->unitLayout($s)])->values()->all();
    }
}
