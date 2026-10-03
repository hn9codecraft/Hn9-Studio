<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Story\Enums\StoryPlanVersionStatus;
use App\Story\Models\StoryPlanVersion;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryReel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsStoryApprovalFixtures;
use Tests\TestCase;

final class StoryPlanApprovalApiTest extends TestCase
{
    use BuildsStoryApprovalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_requests_without_a_session_are_rejected(): void
    {
        $f = $this->approvalFixture();

        $this->postJson($this->url($f))->assertUnauthorized();

        $this->assertFalse($f['version']->refresh()->isApproved());
    }

    public function test_owner_approves_and_gets_the_production_plan(): void
    {
        $f = $this->approvalFixture([47, 10]);

        $response = $this->approveAs($f['user'], $f)
            ->assertCreated()
            ->assertJsonPath('data.approved', true)
            ->assertJsonPath('data.production_plan_created', true)
            ->assertJsonPath('data.version.id', $f['version']->uuid)
            ->assertJsonPath('data.version.review_status', 'approved')
            ->assertJsonPath('data.plan.id', $f['plan']->uuid)
            ->assertJsonPath('data.plan.current_version.review_status', 'approved')
            ->assertJsonPath('data.production_plan.revision', 1)
            ->assertJsonPath('data.production_plan.is_current', true)
            ->assertJsonPath('data.production_plan.unit_seconds', 10)
            ->assertJsonPath('data.production_plan.total_duration_seconds', 57)
            ->assertJsonPath('data.production_plan.scene_count', 2)
            ->assertJsonPath('data.production_plan.unit_count', 6)
            ->assertJsonPath('data.production_plan.source_version.id', $f['version']->uuid)
            ->assertJsonMissingPath('data.production_plan.scenes');

        $planId = $response->json('data.production_plan.id');
        $reel = StoryReel::query()->sole();
        $response->assertJsonPath('data.plan.production_plan.id', $planId)
            ->assertJsonPath('data.production_plan.reel.id', $reel->uuid);
        $this->assertNotNull($response->json('data.version.approved_at'));

        $this->approveAs($f['user'], $f)
            ->assertOk()
            ->assertJsonPath('data.approved', false)
            ->assertJsonPath('data.production_plan_created', false)
            ->assertJsonPath('data.production_plan.id', $planId)
            ->assertJsonPath('data.version.review_status', 'approved');

        $this->assertSame(1, StoryProductionPlan::query()->count());
        $this->assertSame(6, StoryProductionUnit::query()->count());
    }

    public function test_story_plans_show_where_the_review_stands(): void
    {
        $f = $this->approvalFixture([20]);
        $list = "/api/v1/story/projects/{$f['project']->uuid}/plans";

        $this->actingAs($f['user'], 'sanctum')->getJson($list)
            ->assertOk()
            ->assertJsonPath('data.0.current_version.review_status', 'ready_for_review')
            ->assertJsonPath('data.0.current_version.approved_at', null)
            ->assertJsonPath('data.0.production_plan', null);

        $planId = $this->approveAs($f['user'], $f)->json('data.production_plan.id');

        $this->actingAs($f['user'], 'sanctum')->getJson("{$list}/{$f['plan']->uuid}")
            ->assertOk()
            ->assertJsonPath('data.current_version.review_status', 'approved')
            ->assertJsonPath('data.production_plan.id', $planId)
            ->assertJsonPath('data.production_plan.source_version.id', $f['version']->uuid)
            ->assertJsonPath('data.production_plan.scene_count', 1)
            ->assertJsonPath('data.production_plan.unit_count', 2);

        $this->actingAs($f['user'], 'sanctum')->getJson("{$list}/{$f['plan']->uuid}/versions")
            ->assertOk()
            ->assertJsonPath('data.0.review_status', 'approved');
    }

    public function test_admin_can_approve_any_project_story(): void
    {
        $f = $this->approvalFixture([20]);
        $admin = User::factory()->admin()->create();

        $this->approveAs($admin, $f)->assertCreated()->assertJsonPath('data.approved', true);

        $this->assertSame($admin->id, $f['version']->refresh()->approved_by);
    }

    public function test_another_member_cannot_approve(): void
    {
        $f = $this->approvalFixture([20]);

        $this->approveAs(User::factory()->create(), $f)->assertForbidden();

        $this->assertNothingApproved();
    }

    public function test_identifiers_from_another_project_are_not_found(): void
    {
        $mine = $this->approvalFixture([10]);
        $theirs = $this->approvalFixture([10]);
        $base = "/api/v1/story/projects/{$mine['project']->uuid}/plans";

        $attempts = [
            "{$base}/{$theirs['plan']->uuid}/versions/{$theirs['version']->uuid}/approve",
            "{$base}/{$mine['plan']->uuid}/versions/{$theirs['version']->uuid}/approve",
            "{$base}/".Str::uuid()."/versions/{$mine['version']->uuid}/approve",
            "{$base}/{$mine['plan']->uuid}/versions/".Str::uuid().'/approve',
            "{$base}/{$mine['plan']->uuid}/versions/not-a-uuid/approve",
            "{$base}/{$mine['plan']->uuid}/versions/{$mine['version']->id}/approve",
            "{$base}/{$mine['plan']->id}/versions/{$mine['version']->uuid}/approve",
            '/api/v1/story/projects/'.Str::uuid()."/plans/{$mine['plan']->uuid}/versions/{$mine['version']->uuid}/approve",
        ];
        foreach ($attempts as $url) {
            $this->actingAs($mine['user'], 'sanctum')->postJson($url)->assertNotFound();
        }

        $this->assertNothingApproved();
    }

    public function test_client_supplied_fields_are_ignored(): void
    {
        $f = $this->approvalFixture([20]);
        $other = $this->approvalFixture([30]);
        $stranger = User::factory()->create();

        $this->approveAs($f['user'], $f, [
            'approved_by' => $stranger->id,
            'approved_at' => '2001-01-01T00:00:00Z',
            'version_id' => $other['version']->uuid,
            'story_plan_version_id' => $other['version']->id,
            'total_duration_seconds' => 999,
            'unit_seconds' => 30,
        ])->assertCreated()
            ->assertJsonPath('data.production_plan.total_duration_seconds', 20)
            ->assertJsonPath('data.production_plan.unit_seconds', 10);

        $version = $f['version']->refresh();
        $this->assertSame($f['user']->id, $version->approved_by);
        $this->assertTrue($version->approved_at->isToday());
        $this->assertFalse($other['version']->refresh()->isApproved());
        $this->assertSame($f['version']->id, StoryProductionPlan::query()->sole()->story_plan_version_id);
    }

    public function test_versions_that_cannot_be_approved_explain_why(): void
    {
        $f = $this->approvalFixture([20]);
        $f['version']->forceFill(['status' => StoryPlanVersionStatus::Generating->value])->save();
        $this->approveAs($f['user'], $f)->assertStatus(422)->assertJsonPath('error_code', 'story_plan_version_unfinished');

        $f['version']->forceFill(['status' => StoryPlanVersionStatus::Completed->value])->save();
        $newer = $this->reviewableVersion($f['plan'], 2, [30], [0 => ['motion_prompt' => '']]);
        $this->approveAs($f['user'], $f)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_plan_version_not_latest')
            ->assertJsonPath('message', 'A newer version of this story exists. Review and approve the latest version instead.');

        $this->approveAs($f['user'], ['version' => $newer] + $f)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_approval_invalid_plan');

        $this->assertNothingApproved();
    }

    public function test_a_failed_approval_changes_nothing_and_leaks_nothing(): void
    {
        $f = $this->approvalFixture([47]);
        $this->failUnitInserts();

        $body = $this->approveAs($f['user'], $f)
            ->assertStatus(500)
            ->assertJsonPath('error_code', 'story_approval_failed')
            ->assertJsonPath('message', "We couldn't prepare this story for production. Nothing was changed.")
            ->getContent();

        foreach (['SQLSTATE', 'insert into', 'story_production_units', 'disk full', 'trace', '.php'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
        $this->assertNothingApproved();
        $this->assertSame(0, StoryReel::query()->count());
    }

    public function test_history_tells_the_approval_story_without_internal_identifiers(): void
    {
        $f = $this->approvalFixture([20]);
        $this->approveAs($f['user'], $f)->assertCreated();
        $this->approveAs($f['user'], $f)->assertOk();
        $newer = $this->reviewableVersion($f['plan'], 2, [30], [0 => ['story' => '']]);
        $this->approveAs($f['user'], ['version' => $newer] + $f)->assertStatus(422);

        $response = $this->actingAs($f['user'], 'sanctum')
            ->getJson("/api/v1/story/projects/{$f['project']->uuid}/history")
            ->assertOk();
        $items = collect($response->json('data'))->whereIn('kind', ['story_plan', 'production_plan'])->values();

        $this->assertSame([
            ['story_plan', 'approved', 'approved', 'Story version 1'],
            ['production_plan', 'created', 'created', 'Story version 1'],
            ['production_plan', 'reused', 'reused', 'Story version 1'],
            ['story_plan', 'approval_failed', 'failed', 'Story version 2'],
        ], $items->map(static fn (array $i): array => [$i['kind'], $i['event'], $i['status'], $i['version_label']])->all());
        $this->assertSame('The Lost Whale', $items[0]['reel_title']);
        $this->assertSame('story_approval_invalid_plan', $items[3]['error_code']);
        $this->assertStringStartsWith('Some scenes in this story plan are incomplete', $items[3]['error_message']);

        $body = $response->getContent();
        foreach (['story_plan_version_id', 'subject_id', 'approved_by', '"version":'] as $internal) {
            $this->assertStringNotContainsString($internal, $body);
        }
        $this->assertStringNotContainsString((string) $f['version']->uuid, $body);
    }

    public function test_another_member_cannot_read_approval_history(): void
    {
        $f = $this->approvalFixture([20]);
        $this->approveAs($f['user'], $f)->assertCreated();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/story/projects/{$f['project']->uuid}/history")
            ->assertForbidden();
    }

    // ------------------------------------------------------------- helpers --

    /**
     * @param  array<string, mixed>  $f
     */
    private function url(array $f): string
    {
        return "/api/v1/story/projects/{$f['project']->uuid}/plans/{$f['plan']->uuid}/versions/{$f['version']->uuid}/approve";
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $payload
     */
    private function approveAs(User $user, array $f, array $payload = []): TestResponse
    {
        return $this->actingAs($user, 'sanctum')->postJson($this->url($f), $payload);
    }

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

    private function assertNothingApproved(): void
    {
        $this->assertSame(0, StoryPlanVersion::query()->whereNotNull('approved_at')->count());
        $this->assertSame(0, StoryProductionPlan::query()->count());
    }
}
