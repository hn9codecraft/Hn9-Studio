<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionPlanScene;
use App\Story\Models\StoryProductionUnit;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\TestCase;

/**
 * Rules the database itself enforces, independent of the service.
 */
final class StoryProductionPlanSchemaTest extends TestCase
{
    use BuildsProductionPlanFixtures, RefreshDatabase;

    public function test_unit_sequence_and_start_are_unique_within_a_scene(): void
    {
        [, $plan] = $this->planned([20]);
        $scene = $plan->scenes[0];

        $this->assertRejectedBy(UniqueConstraintViolationException::class, fn () => $this->insertUnit($scene->id, 1, 15, 5));
        $this->assertRejectedBy(UniqueConstraintViolationException::class, fn () => $this->insertUnit($scene->id, 3, 10, 5));
        $this->assertSame(2, StoryProductionUnit::query()->count());
    }

    public function test_a_scene_appears_once_per_plan_at_a_unique_position(): void
    {
        [$f, $plan] = $this->planned([10, 10]);

        $this->assertRejectedBy(UniqueConstraintViolationException::class, fn () => $this->insertPlanScene($plan->id, $f['scenes'][0]->id, 3));
        $this->assertRejectedBy(UniqueConstraintViolationException::class, fn () => $this->insertPlanScene($plan->id, $f['scenes'][1]->id, 1));
        $this->assertSame(2, StoryProductionPlanScene::query()->count());
    }

    public function test_a_story_plan_has_one_current_plan_unique_revisions_and_a_linear_history(): void
    {
        [$f, $plan] = $this->planned([10]);
        $row = fn (array $overrides): array => [
            'uuid' => (string) Str::uuid(),
            'story_workspace_id' => $f['workspace']->id,
            'story_plan_id' => $f['plan']->id,
            'story_plan_version_id' => $f['version']->id,
            'story_reel_id' => $f['reel']->id,
            'status' => 'active',
            'unit_seconds' => 10,
            'total_duration_seconds' => 10,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];

        $this->assertRejectedBy(UniqueConstraintViolationException::class, fn () => DB::table('story_production_plans')->insert($row(['revision' => 2, 'current_for_story_plan_id' => $f['plan']->id])));
        $this->assertRejectedBy(UniqueConstraintViolationException::class, fn () => DB::table('story_production_plans')->insert($row(['revision' => 1, 'status' => 'superseded'])));

        DB::table('story_production_plans')->insert($row(['revision' => 2, 'status' => 'superseded', 'previous_plan_id' => $plan->id]));
        $this->assertRejectedBy(UniqueConstraintViolationException::class, fn () => DB::table('story_production_plans')->insert($row(['revision' => 3, 'status' => 'superseded', 'previous_plan_id' => $plan->id])));
    }

    public function test_rows_cannot_point_at_records_that_do_not_exist(): void
    {
        [$f] = $this->planned([10]);

        $this->assertRejectedBy(QueryException::class, fn () => $this->insertUnit(999999, 1, 0, 10));
        $this->assertRejectedBy(QueryException::class, fn () => $this->insertPlanScene(999999, $f['scenes'][0]->id, 1));
        $this->assertRejectedBy(QueryException::class, fn () => DB::table('story_production_plans')->insert([
            'uuid' => (string) Str::uuid(),
            'story_workspace_id' => $f['workspace']->id,
            'story_plan_id' => $f['plan']->id,
            'story_plan_version_id' => 999999,
            'story_reel_id' => $f['reel']->id,
            'revision' => 9,
            'status' => 'superseded',
            'unit_seconds' => 10,
            'total_duration_seconds' => 10,
        ]));
    }

    public function test_upstream_story_records_cannot_be_deleted_from_under_a_plan(): void
    {
        [$f, $plan] = $this->planned([47]);

        $this->assertRejectedBy(QueryException::class, fn () => DB::table('story_scenes')->where('id', $f['scenes'][0]->id)->delete());
        $this->assertRejectedBy(QueryException::class, fn () => DB::table('story_reels')->where('id', $f['reel']->id)->delete());
        $this->assertRejectedBy(QueryException::class, fn () => DB::table('story_plan_versions')->where('id', $f['version']->id)->delete());
        $this->assertRejectedBy(QueryException::class, fn () => DB::table('story_plans')->where('id', $f['plan']->id)->delete());
        $this->assertRejectedBy(QueryException::class, fn () => DB::table('story_workspaces')->where('id', $f['workspace']->id)->delete());
        $this->assertRejectedBy(QueryException::class, fn () => DB::table('projects')->where('id', $f['project']->id)->delete());

        $this->assertSame(5, StoryProductionUnit::query()->count());
        $this->assertTrue(StoryProductionPlan::query()->whereKey($plan->id)->exists());
    }

    public function test_soft_deleting_the_project_keeps_the_production_history(): void
    {
        [$f, $plan] = $this->planned([20]);

        $f['project']->delete();

        $this->assertSoftDeleted(Project::class, ['id' => $f['project']->id]);
        $this->assertTrue(StoryProductionPlan::query()->whereKey($plan->id)->exists());
        $this->assertSame(2, StoryProductionUnit::query()->count());
    }

    public function test_an_earlier_plan_cannot_be_deleted_while_a_later_revision_points_at_it(): void
    {
        [$f, $plan] = $this->planned([10]);
        app(StoryProductionPlanServiceInterface::class)->revise($f['project'], $plan->uuid);

        $this->assertRejectedBy(QueryException::class, fn () => DB::table('story_production_plans')->where('id', $plan->id)->delete());
        $this->assertSame(2, StoryProductionPlan::query()->count());
    }

    public function test_deleting_a_plan_takes_only_its_own_scene_rows_and_units(): void
    {
        [$f, $plan] = $this->planned([25, 5]);
        [, $other] = $this->planned([10]);

        DB::table('story_production_plans')->where('id', $plan->id)->delete();

        $this->assertSame(0, StoryProductionPlanScene::query()->where('story_production_plan_id', $plan->id)->count());
        $this->assertSame(1, StoryProductionUnit::query()->count());
        $this->assertSame(1, StoryProductionPlanScene::query()->where('story_production_plan_id', $other->id)->count());
        $this->assertSame(2, DB::table('story_scenes')->where('story_reel_id', $f['reel']->id)->count());
    }

    public function test_removing_the_creator_keeps_the_plan(): void
    {
        $f = $this->productionFixture([10]);
        $admin = User::factory()->admin()->create();
        $plan = app(StoryProductionPlanServiceInterface::class)->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid, $admin)['plan'];

        DB::table('users')->where('id', $admin->id)->delete();

        $this->assertNull($plan->refresh()->created_by);
    }

    public function test_the_database_rejects_zero_lengths_and_positions(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite cannot add CHECK constraints to an existing table; this runs against MySQL.');
        }

        [, $plan] = $this->planned([10]);
        $scene = $plan->scenes[0];

        $this->assertRejectedBy(QueryException::class, fn () => $this->insertUnit($scene->id, 2, 10, 0));
        $this->assertRejectedBy(QueryException::class, fn () => $this->insertUnit($scene->id, 0, 20, 5));
        $this->assertRejectedBy(QueryException::class, fn () => DB::table('story_production_plan_scenes')->where('id', $scene->id)->update(['duration_seconds' => 0]));
        $this->assertRejectedBy(QueryException::class, fn () => DB::table('story_production_plans')->where('id', $plan->id)->update(['unit_seconds' => 0]));
        $this->assertRejectedBy(QueryException::class, fn () => DB::table('story_production_plans')->where('id', $plan->id)->update(['revision' => 0]));
    }

    /**
     * @param  list<int>  $durations
     * @return array{0: array<string, mixed>, 1: StoryProductionPlan}
     */
    private function planned(array $durations): array
    {
        $f = $this->productionFixture($durations);
        $plan = app(StoryProductionPlanServiceInterface::class)->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid)['plan'];

        return [$f, $plan];
    }

    private function insertUnit(int $planSceneId, int $sequence, int $start, int $duration): void
    {
        DB::table('story_production_units')->insert([
            'uuid' => (string) Str::uuid(),
            'story_production_plan_scene_id' => $planSceneId,
            'sequence' => $sequence,
            'start_second' => $start,
            'duration_seconds' => $duration,
        ]);
    }

    private function insertPlanScene(int $planId, int $sceneId, int $sequence): void
    {
        DB::table('story_production_plan_scenes')->insert([
            'uuid' => (string) Str::uuid(),
            'story_production_plan_id' => $planId,
            'story_scene_id' => $sceneId,
            'sequence' => $sequence,
            'start_second' => 0,
            'duration_seconds' => 10,
        ]);
    }

    /**
     * Each attempt runs in its own savepoint so a rejected statement cannot poison the test transaction.
     *
     * @param  class-string<\Throwable>  $expected
     */
    private function assertRejectedBy(string $expected, callable $statement): void
    {
        try {
            DB::transaction($statement);
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($expected, $exception, $exception->getMessage());

            return;
        }

        $this->fail("Expected the database to reject the statement with {$expected}.");
    }
}
