<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Models\StoryProductionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\TestCase;

final class StoryProductionPlanApiTest extends TestCase
{
    use BuildsProductionPlanFixtures, RefreshDatabase;

    public function test_requests_without_a_session_are_rejected(): void
    {
        [$project, $plan, $scene] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

        $this->getJson("/api/v1/story/projects/{$project}/production-plans")->assertUnauthorized();
        $this->getJson("/api/v1/story/projects/{$project}/production-plans/{$plan}")->assertUnauthorized();
        $this->getJson("/api/v1/story/projects/{$project}/production-plans/{$plan}/scenes/{$scene}")->assertUnauthorized();
    }

    public function test_owner_reads_the_plan_scenes_and_units(): void
    {
        Http::fake();
        [$f, $plan] = $this->planned([17, 10]);
        $base = "/api/v1/story/projects/{$f['project']->uuid}/production-plans";

        $this->actingAs($f['user'], 'sanctum')->getJson($base)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $plan->uuid)
            ->assertJsonPath('data.0.revision', 1)
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.0.scene_count', 2)
            ->assertJsonPath('data.0.unit_count', 3)
            ->assertJsonMissingPath('data.0.scenes');

        $detail = $this->actingAs($f['user'], 'sanctum')->getJson("{$base}/{$plan->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.unit_seconds', 10)
            ->assertJsonPath('data.total_duration_seconds', 27)
            ->assertJsonPath('data.story_plan.id', $f['plan']->uuid)
            ->assertJsonPath('data.source_version.id', $f['version']->uuid)
            ->assertJsonPath('data.source_version.version', 1)
            ->assertJsonPath('data.reel.id', $f['reel']->uuid)
            ->assertJsonPath('data.previous_plan_id', null)
            ->assertJsonPath('data.scenes.0.scene_id', $f['scenes'][0]->uuid)
            ->assertJsonPath('data.scenes.1.start_second', 17)
            ->assertJsonPath('data.scenes.1.end_second', 27)
            ->json('data');

        $this->assertSame([
            ['sequence' => 1, 'start_second' => 0, 'duration_seconds' => 10, 'end_second' => 10, 'kind' => 'standard'],
            ['sequence' => 2, 'start_second' => 10, 'duration_seconds' => 7, 'end_second' => 17, 'kind' => 'remainder'],
        ], array_map(static fn (array $unit): array => array_diff_key($unit, ['id' => true]), $detail['scenes'][0]['units']));
        foreach ($detail['scenes'][0]['units'] as $unit) {
            $this->assertTrue(Str::isUuid($unit['id']));
        }

        $this->actingAs($f['user'], 'sanctum')->getJson("{$base}/{$plan->uuid}/scenes/{$f['scenes'][1]->uuid}")
            ->assertOk()
            ->assertJsonPath('data.sequence', 2)
            ->assertJsonPath('data.unit_count', 1)
            ->assertJsonPath('data.units.0.kind', 'standard');

        Http::assertNothingSent();
    }

    public function test_responses_expose_no_internal_identifiers(): void
    {
        [$f, $plan] = $this->planned([12]);

        $body = $this->actingAs($f['user'], 'sanctum')
            ->getJson("/api/v1/story/projects/{$f['project']->uuid}/production-plans/{$plan->uuid}")
            ->assertOk()
            ->getContent();

        foreach (['story_workspace_id', 'story_plan_id', 'story_plan_version_id', 'story_reel_id', 'story_scene_id', 'story_scene_version_id', 'current_for_story_plan_id', 'created_by', 'story_production_plan_id', 'story_production_plan_scene_id'] as $column) {
            $this->assertStringNotContainsString($column, $body);
        }
    }

    public function test_revision_history_is_readable(): void
    {
        [$f, $a] = $this->planned([10]);
        $b = app(StoryProductionPlanServiceInterface::class)->revise($f['project'], $a->uuid)['plan'];
        $base = "/api/v1/story/projects/{$f['project']->uuid}/production-plans";

        $this->actingAs($f['user'], 'sanctum')->getJson($base)
            ->assertOk()
            ->assertJsonPath('data.0.id', $b->uuid)
            ->assertJsonPath('data.0.previous_plan_id', $a->uuid)
            ->assertJsonPath('data.1.id', $a->uuid)
            ->assertJsonPath('data.1.status', 'superseded')
            ->assertJsonPath('data.1.is_current', false);

        $this->actingAs($f['user'], 'sanctum')->getJson("{$base}/{$a->uuid}")
            ->assertOk()
            ->assertJsonPath('data.next_plan_id', $b->uuid)
            ->assertJsonPath('data.scenes.0.units.0.duration_seconds', 10);
    }

    public function test_admin_can_read_any_project_plan(): void
    {
        [$f, $plan] = $this->planned([10]);

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson("/api/v1/story/projects/{$f['project']->uuid}/production-plans/{$plan->uuid}")
            ->assertOk()
            ->assertJsonPath('data.id', $plan->uuid);
    }

    public function test_another_member_cannot_read_the_plan(): void
    {
        [$f, $plan] = $this->planned([10]);
        $stranger = User::factory()->create();
        $base = "/api/v1/story/projects/{$f['project']->uuid}/production-plans";

        $this->actingAs($stranger, 'sanctum')->getJson($base)->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->getJson("{$base}/{$plan->uuid}")->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->getJson("{$base}/{$plan->uuid}/scenes/{$f['scenes'][0]->uuid}")->assertForbidden();
    }

    public function test_identifiers_from_another_project_are_not_found(): void
    {
        [$mine, $myPlan] = $this->planned([10]);
        [$theirs, $theirPlan] = $this->planned([10]);
        $base = "/api/v1/story/projects/{$mine['project']->uuid}/production-plans";

        $this->actingAs($mine['user'], 'sanctum')->getJson("{$base}/{$theirPlan->uuid}")->assertNotFound();
        $this->actingAs($mine['user'], 'sanctum')->getJson("{$base}/{$theirPlan->uuid}/scenes/{$theirs['scenes'][0]->uuid}")->assertNotFound();
        $this->actingAs($mine['user'], 'sanctum')->getJson("{$base}/{$myPlan->uuid}/scenes/{$theirs['scenes'][0]->uuid}")->assertNotFound();
        $this->actingAs($mine['user'], 'sanctum')->getJson($base)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $myPlan->uuid);
    }

    public function test_unknown_or_malformed_identifiers_are_not_found(): void
    {
        [$f, $plan] = $this->planned([10]);
        $base = "/api/v1/story/projects/{$f['project']->uuid}/production-plans";

        $this->actingAs($f['user'], 'sanctum')->getJson($base.'/'.Str::uuid())->assertNotFound();
        $this->actingAs($f['user'], 'sanctum')->getJson("{$base}/not-a-uuid")->assertNotFound();
        $this->actingAs($f['user'], 'sanctum')->getJson("{$base}/{$plan->id}")->assertNotFound();
        $this->actingAs($f['user'], 'sanctum')->getJson("{$base}/{$plan->uuid}/scenes/".Str::uuid())->assertNotFound();
        $this->actingAs($f['user'], 'sanctum')->getJson('/api/v1/story/projects/'.Str::uuid().'/production-plans')->assertNotFound();
    }

    public function test_plans_cannot_be_written_through_the_api(): void
    {
        [$f, $plan] = $this->planned([10]);
        $base = "/api/v1/story/projects/{$f['project']->uuid}/production-plans";

        $this->actingAs($f['user'], 'sanctum')->postJson($base, ['story_plan_version_id' => $f['version']->uuid])->assertStatus(405);
        $this->actingAs($f['user'], 'sanctum')->patchJson("{$base}/{$plan->uuid}", ['total_duration_seconds' => 1])->assertStatus(405);
        $this->actingAs($f['user'], 'sanctum')->deleteJson("{$base}/{$plan->uuid}")->assertStatus(405);

        $this->assertSame(10, StoryProductionPlan::query()->sole()->total_duration_seconds);
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
}
