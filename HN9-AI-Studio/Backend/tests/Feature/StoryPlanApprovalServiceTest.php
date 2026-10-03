<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Story\Contracts\StoryPlanApprovalServiceInterface;
use App\Story\Contracts\StoryPlanMaterializerInterface;
use App\Story\Enums\StoryPlanStatus;
use App\Story\Enums\StoryPlanVersionStatus;
use App\Story\Enums\StoryReelStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryPlanVersion;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionPlanScene;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Services\StoryPlanApprovalService;
use App\Story\Services\StoryProductionPlanService;
use App\Story\Support\StoryGenerationUnitCalculator;
use App\Story\Support\StoryGenerationUnitSlot;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\BuildsStoryApprovalFixtures;
use Tests\TestCase;

final class StoryPlanApprovalServiceTest extends TestCase
{
    use BuildsStoryApprovalFixtures, RefreshDatabase;

    private StoryPlanApprovalServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->service = app(StoryPlanApprovalServiceInterface::class);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    // ------------------------------------------------------------ approval --

    public function test_approval_turns_that_exact_version_into_scenes_and_ten_second_units(): void
    {
        $f = $this->approvalFixture([47, 10, 5]);

        $result = $this->approve($f);
        $plan = $result['production_plan'];
        $version = $f['version']->refresh();

        $this->assertTrue($result['approved']);
        $this->assertTrue($result['created']);
        $this->assertTrue($version->isApproved());
        $this->assertSame($f['user']->id, $version->approved_by);
        $this->assertSame('approved', $version->reviewStatus()->value);
        $this->assertSame($version->id, $result['version']->id);

        $reel = StoryReel::query()->sole();
        $this->assertSame($version->id, $reel->source_plan_version_id);
        $this->assertSame([47, 10, 5], $reel->scenes()->orderBy('sequence')->pluck('duration_seconds')->all());
        $this->assertSame(3, StoryScene::query()->where('source_plan_version_id', $version->id)->count());

        $this->assertSame($version->id, $plan->story_plan_version_id);
        $this->assertSame($reel->id, $plan->story_reel_id);
        $this->assertSame(1, $plan->revision);
        $this->assertTrue($plan->isCurrent());
        $this->assertSame(62, $plan->total_duration_seconds);
        $this->assertSame($f['user']->id, $plan->created_by);
        $this->assertSame($plan->id, $result['plan']->currentProductionPlan?->id);
        $this->assertSame('approved', $result['plan']->currentVersion->reviewStatus()->value);

        $this->assertSame(1, ActivityLog::query()->where('action', StoryPlanApprovalService::EVENT_APPROVED)->where('subject_id', $version->id)->count());
        $this->assertSame(1, ActivityLog::query()->where('action', StoryProductionPlanService::EVENT_CREATED)->count());
    }

    public function test_approval_created_plans_keep_every_generation_unit_invariant(): void
    {
        $durations = [1, 9, 10, 11, 29, 30, 31, 47, 120, 3600];
        $f = $this->approvalFixture($durations);
        $calculator = app(StoryGenerationUnitCalculator::class);

        $plan = $this->approve($f)['production_plan'];

        $this->assertSame(array_sum($durations), $plan->total_duration_seconds);
        $planCursor = 0;
        foreach ($plan->scenes as $index => $scene) {
            $this->assertSame($durations[$index], $scene->duration_seconds);
            $this->assertSame($planCursor, $scene->start_second);
            $expected = array_map(static fn (StoryGenerationUnitSlot $s): array => [$s->sequence, $s->startSecond, $s->durationSeconds], $calculator->split($scene->duration_seconds));
            $this->assertSame($expected, $this->unitLayout($scene));

            foreach ($scene->units as $unit) {
                $this->assertGreaterThanOrEqual(1, $unit->duration_seconds);
                $this->assertLessThanOrEqual(StoryGenerationUnitCalculator::UNIT_SECONDS, $unit->duration_seconds);
            }
            $this->assertSame($scene->duration_seconds, (int) $scene->units->sum('duration_seconds'));
            $planCursor += $scene->duration_seconds;
        }
        $this->assertSame(array_sum(array_map(static fn (int $s): int => intdiv($s + 9, 10), $durations)), StoryProductionUnit::query()->count());
    }

    // ---------------------------------------------------------- eligibility --

    public function test_unfinished_and_failed_versions_cannot_be_approved(): void
    {
        $cases = [
            [StoryPlanVersionStatus::Generating, 'story_plan_version_unfinished'],
            [StoryPlanVersionStatus::Failed, 'story_plan_version_failed'],
        ];
        foreach ($cases as [$status, $code]) {
            $f = $this->approvalFixture([20]);
            $f['version']->forceFill(['status' => $status->value])->save();

            $this->assertRejected($code, fn () => $this->approve($f));
            $this->assertFalse($f['version']->refresh()->isApproved());
        }
        $this->assertNothingCreated();
        $this->assertSame(2, ActivityLog::query()->where('action', StoryPlanApprovalService::EVENT_APPROVAL_FAILED)->count());
    }

    public function test_only_the_latest_version_can_be_approved(): void
    {
        $f = $this->approvalFixture([20]);
        $this->reviewableVersion($f['plan'], 2, [30]);

        $exception = $this->assertRejected('story_plan_version_not_latest', fn () => $this->approve($f));
        $this->assertSame(409, $exception->statusCode());

        $generating = $this->reviewableVersion($f['plan'], 3, [30]);
        $generating->forceFill(['status' => StoryPlanVersionStatus::Generating->value])->save();
        $this->assertRejected('story_plan_version_not_latest', fn () => $this->approve($f));

        $this->assertFalse($f['version']->refresh()->isApproved());
        $this->assertNothingCreated();
    }

    public function test_a_plan_with_incomplete_scenes_cannot_be_approved(): void
    {
        $cases = [
            'missing story' => [[20, 10], [1 => ['story' => '']]],
            'missing visual' => [[20], [0 => ['visual_prompt' => null]]],
            'zero seconds' => [[20, 0], []],
            'too long' => [[3601], []],
            'no scenes' => [[], []],
        ];
        foreach ($cases as $label => [$durations, $overrides]) {
            $f = $this->approvalFixture([10]);
            $version = $this->reviewableVersion($f['plan'], 2, $durations, $overrides);

            $exception = $this->assertRejected('story_approval_invalid_plan', fn () => $this->service->approve($f['project'], $f['plan']->uuid, $version->uuid, $f['user']), $label);
            $this->assertSame(422, $exception->statusCode());
            $this->assertFalse($version->refresh()->isApproved(), $label);
        }
        $this->assertNothingCreated();
    }

    public function test_a_plan_whose_payload_is_not_structured_cannot_be_approved(): void
    {
        $f = $this->approvalFixture([10]);
        StoryPlanVersion::query()->whereKey($f['version']->id)->update(['plan' => null]);

        $this->assertRejected('story_approval_invalid_plan', fn () => $this->approve($f));
        $this->assertNothingCreated();
    }

    public function test_an_archived_reel_blocks_approval_and_rolls_it_back(): void
    {
        $f = $this->approvalFixture([20]);
        $reel = app(StoryPlanMaterializerInterface::class)->materialize($f['project'], $f['plan']->uuid, $f['version']->uuid)['reel'];
        $reel->forceFill(['status' => StoryReelStatus::Archived->value])->save();

        $this->assertRejected('story_production_source_not_ready', fn () => $this->approve($f));

        $this->assertFalse($f['version']->refresh()->isApproved());
        $this->assertSame(0, StoryProductionPlan::query()->count());
        $this->assertSame(0, ActivityLog::query()->where('action', StoryPlanApprovalService::EVENT_APPROVED)->count());
    }

    public function test_a_scene_from_another_story_version_blocks_approval(): void
    {
        $f = $this->approvalFixture([20, 10]);
        $reel = app(StoryPlanMaterializerInterface::class)->materialize($f['project'], $f['plan']->uuid, $f['version']->uuid)['reel'];
        $otherStory = StoryPlan::factory()->create(['story_workspace_id' => $f['workspace']->id, 'status' => StoryPlanStatus::Completed->value]);
        $other = $this->reviewableVersion($otherStory, 1, [10]);
        $reel->scenes()->orderBy('sequence')->skip(1)->first()->forceFill(['source_plan_version_id' => $other->id])->save();

        $this->assertRejected('story_production_invalid_scene', fn () => $this->approve($f));

        $this->assertFalse($f['version']->refresh()->isApproved());
        $this->assertSame(0, StoryProductionPlan::query()->count());
    }

    public function test_identifiers_from_another_project_or_story_are_not_found(): void
    {
        $mine = $this->approvalFixture([10]);
        $theirs = $this->approvalFixture([10]);
        $sibling = StoryPlan::factory()->create(['story_workspace_id' => $mine['workspace']->id, 'status' => StoryPlanStatus::Completed->value]);
        $siblingVersion = $this->reviewableVersion($sibling, 1, [10]);

        $attempts = [
            [$theirs['plan']->uuid, $theirs['version']->uuid],
            [$mine['plan']->uuid, $theirs['version']->uuid],
            [$mine['plan']->uuid, $siblingVersion->uuid],
            [(string) Str::uuid(), $mine['version']->uuid],
            [$mine['plan']->uuid, (string) Str::uuid()],
            [$mine['plan']->uuid, (string) $mine['version']->id],
        ];
        foreach ($attempts as [$planUuid, $versionUuid]) {
            $this->assertRejected('story_not_found', fn () => $this->service->approve($mine['project'], $planUuid, $versionUuid, $mine['user']));
        }

        $this->assertFalse($theirs['version']->refresh()->isApproved());
        $this->assertFalse($siblingVersion->refresh()->isApproved());
        $this->assertNothingCreated();
        $this->assertSame(0, ActivityLog::query()->where('action', StoryPlanApprovalService::EVENT_APPROVAL_FAILED)->count());
    }

    // ------------------------------------------------- idempotency & races --

    public function test_repeating_approval_reuses_the_plan_without_duplicates(): void
    {
        $f = $this->approvalFixture([47, 10]);

        $first = $this->approve($f);
        $approvedAt = $f['version']->refresh()->approved_at;
        $this->travel(5)->minutes();
        $again = $this->approve($f, User::factory()->admin()->create());

        $this->assertFalse($again['approved']);
        $this->assertFalse($again['created']);
        $this->assertSame($first['production_plan']->id, $again['production_plan']->id);
        $this->assertEquals($approvedAt, $f['version']->refresh()->approved_at);
        $this->assertSame($f['user']->id, $f['version']->approved_by);

        $this->assertSame(1, StoryReel::query()->count());
        $this->assertSame(2, StoryScene::query()->count());
        $this->assertSame(1, StoryProductionPlan::query()->count());
        $this->assertSame(2, StoryProductionPlanScene::query()->count());
        $this->assertSame(6, StoryProductionUnit::query()->count());
        $this->assertSame(1, ActivityLog::query()->where('action', StoryPlanApprovalService::EVENT_APPROVED)->count());
        $this->assertSame(1, ActivityLog::query()->where('action', StoryProductionPlanService::EVENT_CREATED)->count());
        $this->assertSame(1, ActivityLog::query()->where('action', StoryPlanApprovalService::EVENT_PLAN_REUSED)->count());
    }

    public function test_an_approval_that_another_request_finished_first_is_reused(): void
    {
        $f = $this->approvalFixture([25]);
        $state = ['competitor' => null];

        // The "other request" approves the version just before this one takes the story lock.
        Event::listen(TransactionBeginning::class, function () use (&$state, $f): void {
            if ($state['competitor'] === null) {
                $state['competitor'] = false;
                $state['competitor'] = $this->approve($f);
            }
        });

        $result = $this->approve($f);

        $this->assertTrue($state['competitor']['created']);
        $this->assertFalse($result['approved']);
        $this->assertFalse($result['created']);
        $this->assertSame($state['competitor']['production_plan']->id, $result['production_plan']->id);
        $this->assertSame(1, StoryReel::query()->count());
        $this->assertSame(1, StoryProductionPlan::query()->count());
        $this->assertSame(3, StoryProductionUnit::query()->count());
        $this->assertSame(1, ActivityLog::query()->where('action', StoryPlanApprovalService::EVENT_APPROVED)->count());
    }

    public function test_scenes_created_by_a_concurrent_request_are_reused(): void
    {
        $f = $this->approvalFixture([20, 10]);
        $materializer = app(StoryPlanMaterializerInterface::class);
        $state = ['competitor' => null];

        // The other request creates the scenes after this one checked for them but before it locks.
        Event::listen(TransactionBeginning::class, function () use (&$state, $f, $materializer): void {
            if ($state['competitor'] === null) {
                $state['competitor'] = false;
                $state['competitor'] = $materializer->materialize($f['project'], $f['plan']->uuid, $f['version']->uuid);
            }
        });

        $result = $materializer->materialize($f['project'], $f['plan']->uuid, $f['version']->uuid);

        $this->assertTrue($state['competitor']['created']);
        $this->assertFalse($result['created']);
        $this->assertSame($state['competitor']['reel']->id, $result['reel']->id);
        $this->assertSame(1, StoryReel::query()->count());
        $this->assertSame(2, StoryScene::query()->count());
    }

    // ---------------------------------------------------------- atomicity --

    public function test_a_failure_while_writing_units_rolls_back_the_whole_approval(): void
    {
        $f = $this->approvalFixture([47, 10]);
        $this->failInserts('story_production_units');
        Log::spy();

        $exception = $this->assertRejected('story_approval_failed', fn () => $this->approve($f));

        $this->assertSame(500, $exception->statusCode());
        $this->assertSame("We couldn't prepare this story for production. Nothing was changed.", $exception->getMessage());
        $this->assertFalse($f['version']->refresh()->isApproved());
        $this->assertNull($f['version']->approved_by);
        $this->assertNothingCreated();
        $this->assertSame(0, ActivityLog::query()->whereIn('action', [StoryPlanApprovalService::EVENT_APPROVED, StoryProductionPlanService::EVENT_CREATED])->count());

        $failure = ActivityLog::query()->where('action', StoryPlanApprovalService::EVENT_APPROVAL_FAILED)->sole();
        $this->assertSame($f['version']->id, (int) $failure->subject_id);
        $this->assertSame('story_approval_failed', $failure->properties['error_code']);
        $this->assertStringNotContainsString('disk full', json_encode($failure->properties));

        Log::shouldHaveReceived('error')->once()->withArgs(static fn (string $message, array $context): bool => $message === 'Production plan write failed and was rolled back.'
            && str_contains($context['message'], 'disk full')
            && ! str_contains($context['message'], 'insert into'));
    }

    public function test_a_failure_while_creating_scenes_rolls_back_the_whole_approval(): void
    {
        $f = $this->approvalFixture([20, 10]);
        $this->failInserts('story_scenes');
        Log::spy();

        $this->assertRejected('story_approval_failed', fn () => $this->approve($f));

        $this->assertFalse($f['version']->refresh()->isApproved());
        $this->assertNothingCreated();
        $this->assertSame(1, ActivityLog::query()->where('action', StoryPlanApprovalService::EVENT_APPROVAL_FAILED)->count());
        Log::shouldHaveReceived('error')->once()->withArgs(static fn (string $message, array $context): bool => $message === 'Story approval failed and was rolled back.'
            && $context['story_plan_version'] === $f['version']->uuid
            && $context['exception'] === QueryException::class
            && str_contains($context['message'], 'disk full')
            && ! str_contains($context['message'], 'insert into'));
    }

    // ---------------------------------------------------------- versioning --

    public function test_each_approved_version_gets_its_own_plan_and_earlier_plans_stay_intact(): void
    {
        $f = $this->approvalFixture([47]);
        $a = $this->approve($f)['production_plan'];
        $aUnits = StoryProductionUnit::query()->orderBy('id')->get(['id', 'uuid', 'start_second', 'duration_seconds'])->toArray();
        $aLayout = $this->planLayout($a);

        $v2 = $this->reviewableVersion($f['plan'], 2, [30, 15]);
        $result = $this->service->approve($f['project'], $f['plan']->uuid, $v2->uuid, $f['user']);
        $b = $result['production_plan'];
        $a->refresh();

        $this->assertTrue($result['approved']);
        $this->assertTrue($result['created']);
        $this->assertSame([1, 2], [$a->revision, $b->revision]);
        $this->assertSame($a->id, $b->previous_plan_id);
        $this->assertSame($v2->id, $b->story_plan_version_id);
        $this->assertSame($f['version']->id, $a->story_plan_version_id);
        $this->assertSame(['superseded', 'active'], [$a->status, $b->status]);
        $this->assertTrue($b->isCurrent());
        $this->assertNotSame($a->story_reel_id, $b->story_reel_id);
        $this->assertSame(45, $b->total_duration_seconds);
        $this->assertTrue($f['version']->refresh()->isApproved());

        $this->assertSame($aLayout, $this->planLayout(StoryProductionPlan::query()->with('scenes.units')->findOrFail($a->id)));
        $this->assertSame($aUnits, StoryProductionUnit::query()->whereIn('story_production_plan_scene_id', $a->scenes()->pluck('id'))->orderBy('id')->get(['id', 'uuid', 'start_second', 'duration_seconds'])->toArray());
        $this->assertSame(1, ActivityLog::query()->where('action', StoryProductionPlanService::EVENT_REVISED)->count());

        $again = $this->approve($f);
        $this->assertSame($a->id, $again['production_plan']->id);
        $this->assertFalse($again['created']);
        $this->assertSame(2, StoryProductionPlan::query()->count());
        $this->assertTrue($b->refresh()->isCurrent());
    }

    // ------------------------------------------------------------- helpers --

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    private function approve(array $f, ?User $actor = null): array
    {
        return $this->service->approve($f['project'], $f['plan']->uuid, $f['version']->uuid, $actor ?? $f['user']);
    }

    /**
     * MySQL trigger DDL would commit the test transaction, so other drivers fail from a query hook.
     */
    private function failInserts(string $table): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER fail_{$table}_insert BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'disk full'); END;");

            return;
        }

        DB::listen(static function (QueryExecuted $query) use ($table): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, "`{$table}`")) {
                throw new QueryException($query->connectionName, $query->sql, $query->bindings, new \PDOException('disk full'));
            }
        });
    }

    private function assertRejected(string $code, callable $callback, string $label = ''): StoryException
    {
        try {
            $callback();
        } catch (StoryException $exception) {
            $this->assertSame($code, $exception->errorCode(), trim($label.' '.$exception->getMessage()));

            return $exception;
        }

        $this->fail(trim("Expected {$code}. {$label}"));
    }

    private function assertNothingCreated(): void
    {
        $this->assertSame(0, StoryReel::query()->count());
        $this->assertSame(0, StoryScene::query()->count());
        $this->assertSame(0, StoryProductionPlan::query()->count());
        $this->assertSame(0, StoryProductionPlanScene::query()->count());
        $this->assertSame(0, StoryProductionUnit::query()->count());
        $this->assertSame(0, StoryPlanVersion::query()->whereNotNull('approved_at')->count());
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
