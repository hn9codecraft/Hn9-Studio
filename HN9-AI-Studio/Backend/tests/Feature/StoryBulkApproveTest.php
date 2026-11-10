<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryProductionUnitVersion;
use App\Story\Models\StorySceneAudio;
use App\Story\Models\StoryVideoGenerationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\TestCase;

final class StoryBulkApproveTest extends TestCase
{
    use BuildsProductionPlanFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake('videos');
        Storage::fake('images');
        config(['story_cost.video' => []]);
    }

    public function test_bulk_routes_reject_anonymous_callers(): void
    {
        $f = $this->productionFixture([10]);
        $this->postJson($this->url($f, '/bulk-approve/preview'), ['kind' => 'unit_version', 'ids' => ['x']])->assertUnauthorized();
        $this->postJson($this->url($f, '/bulk-approve'), [
            'kind' => 'unit_version',
            'ids' => ['x'],
            'selection_hash' => str_repeat('b', 64),
        ])->assertUnauthorized();
    }

    public function test_preview_explains_eligibility_and_does_not_write_or_charge(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $ready = $this->version($unit, 'clips/ready.mp4');
        $rework = $this->version($unit, 'clips/rework.mp4', 'needs_rework', 2);
        $jobs = StoryVideoGenerationJob::query()->count();

        $preview = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, '/bulk-approve/preview'), [
                'kind' => 'unit_version',
                'scope' => ['plan_uuid' => $plan->uuid],
                'ids' => [$ready->uuid, $rework->uuid],
            ])
            ->assertOk()
            ->json('data');

        $reasons = collect($preview['items'])->keyBy('id');
        $this->assertSame('conflict', $reasons[$ready->uuid]['reason']);
        $this->assertFalse($reasons[$ready->uuid]['eligible']);
        $this->assertSame('conflict', $reasons[$rework->uuid]['reason']);
        $this->assertTrue($preview['items'][0]['cost_free']);
        $this->assertFalse($preview['next_paid_step']['available']);
        $this->assertStringContainsString('Units still without a clip', $preview['next_paid_step']['label']);
        $this->assertSame(64, strlen($preview['selection_hash']));
        $this->assertSame($jobs, StoryVideoGenerationJob::query()->count());
        $this->assertSame('pending_review', $ready->fresh()->review_status);
        Http::assertNothingSent();

        $only = $this->version($this->extraUnit($unit), 'clips/only.mp4');
        $single = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, '/bulk-approve/preview'), [
                'kind' => 'unit_version',
                'scope' => ['plan_uuid' => $plan->uuid],
                'ids' => [$only->uuid],
            ])
            ->assertOk()
            ->json('data');
        $this->assertTrue($single['items'][0]['eligible']);
        $this->assertNull($single['items'][0]['reason']);

        $plan->forceFill(['status' => 'superseded'])->save();
        $superseded = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, '/bulk-approve/preview'), [
                'kind' => 'unit_version',
                'scope' => ['plan_uuid' => $plan->uuid],
                'ids' => [$only->uuid],
            ])
            ->assertOk()
            ->json('data');
        $this->assertSame('superseded_plan', $superseded['items'][0]['reason']);
    }

    public function test_approve_changes_only_the_eligible_selection_and_keeps_a_failure_local(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $good = $this->version($unit, 'clips/good.mp4');
        $bad = $this->version($this->extraUnit($unit), 'clips/bad.mp4');
        $approvedAlready = $this->version($this->extraUnit($unit), 'clips/done.mp4', 'approved');
        $foreign = $this->version($this->ready()[2], 'clips/foreign.mp4');
        $jobs = StoryVideoGenerationJob::query()->count();

        StoryProductionUnitVersion::updated(function () use ($bad): void {
            Storage::disk('videos')->delete($bad->path);
        });

        $preview = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, '/bulk-approve/preview'), [
                'kind' => 'unit_version',
                'scope' => ['plan_uuid' => $plan->uuid],
                'ids' => [$good->uuid, $bad->uuid, $approvedAlready->uuid, $foreign->uuid],
            ])
            ->assertOk()
            ->json('data');

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, '/bulk-approve'), [
                'kind' => 'unit_version',
                'scope' => ['plan_uuid' => $plan->uuid],
                'ids' => [$good->uuid, $bad->uuid, $approvedAlready->uuid, $foreign->uuid],
                'selection_hash' => str_repeat('a', 64),
            ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_bulk_selection_changed');
        $this->assertSame('pending_review', $good->fresh()->review_status);

        $result = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, '/bulk-approve'), [
                'kind' => 'unit_version',
                'scope' => ['plan_uuid' => $plan->uuid],
                'ids' => [$good->uuid, $bad->uuid, $approvedAlready->uuid, $foreign->uuid],
                'selection_hash' => $preview['selection_hash'],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame([$good->uuid], $result['approved']);
        $this->assertSame('approved', $good->fresh()->review_status);
        $this->assertSame('pending_review', $bad->fresh()->review_status);
        $skipped = collect($result['skipped'])->keyBy('id');
        $this->assertSame('already_approved', $skipped[$approvedAlready->uuid]['reason']);
        $this->assertSame('not_in_scope', $skipped[$foreign->uuid]['reason']);
        $this->assertSame($bad->uuid, $result['failed'][0]['id']);
        $this->assertSame(1, ActivityLog::query()->where('action', 'story.unit_version.approved')->count());
        $this->assertSame(1, ActivityLog::query()->where('action', 'story.bulk_approved')->count());
        $this->assertSame($jobs, StoryVideoGenerationJob::query()->count());
        Http::assertNothingSent();
    }

    public function test_invalid_kinds_empty_selections_and_a_held_lock_are_refused(): void
    {
        [$f, $plan, $unit] = $this->ready();
        $version = $this->version($unit, 'clips/lock.mp4');
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, '/bulk-approve/preview'), ['kind' => 'plan_version', 'ids' => [$version->uuid]])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_bulk_kind_invalid');
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, '/bulk-approve/preview'), ['kind' => 'reel', 'ids' => [$version->uuid]])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_bulk_kind_invalid');
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, '/bulk-approve/preview'), ['kind' => 'render', 'ids' => [$version->uuid]])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_bulk_kind_invalid');
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, '/bulk-approve/preview'), ['kind' => 'unit_version', 'scope' => ['plan_uuid' => $plan->uuid], 'ids' => []])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_bulk_selection_invalid');
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, '/bulk-approve/preview'), [
                'kind' => 'unit_version',
                'scope' => ['plan_uuid' => $plan->uuid],
                'ids' => array_map(static fn (int $index): string => sprintf('00000000-0000-4000-8000-%012d', $index), range(1, 51)),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_bulk_selection_invalid');

        $preview = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, '/bulk-approve/preview'), [
                'kind' => 'unit_version',
                'scope' => ['plan_uuid' => $plan->uuid],
                'ids' => [$version->uuid],
            ])
            ->assertOk()
            ->json('data');
        $held = Cache::lock('story-bulk-approve:'.$f['workspace']->uuid.':unit_version', 120);
        $this->assertTrue($held->get());
        try {
            $this->actingAs($f['user'], 'sanctum')
                ->postJson($this->url($f, '/bulk-approve'), [
                    'kind' => 'unit_version',
                    'scope' => ['plan_uuid' => $plan->uuid],
                    'ids' => [$version->uuid],
                    'selection_hash' => $preview['selection_hash'],
                ])
                ->assertStatus(409)
                ->assertJsonPath('error_code', 'story_bulk_in_progress');
        } finally {
            $held->release();
        }
        $this->assertSame('pending_review', $version->fresh()->review_status);
    }

    public function test_character_pictures_and_voiceovers_can_be_previewed_and_routes_are_guarded(): void
    {
        [$f, $plan] = $this->ready();
        unset($plan);
        $character = StoryCharacter::factory()->create(['story_workspace_id' => $f['workspace']->id, 'name' => 'Aarav']);
        $picture = StoryCharacterReference::factory()->create([
            'story_character_id' => $character->id,
            'status' => 'pending_review',
        ]);
        Storage::disk('images')->put($picture->path, 'picture');
        $preview = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, '/bulk-approve/preview'), [
                'kind' => 'character_reference',
                'ids' => [$picture->uuid],
            ])
            ->assertOk()
            ->json('data');
        $this->assertTrue($preview['items'][0]['eligible']);
        $this->assertSame('Locking a character is free.', $preview['next_paid_step']['label']);

        $audio = new StorySceneAudio;
        $audio->forceFill([
            'story_workspace_id' => $f['workspace']->id,
            'story_scene_id' => $f['scenes'][0]->id,
            'role' => 'voice',
            'status' => 'completed',
            'review_status' => 'pending_review',
            'disk' => 'videos',
            'path' => 'voice/one.mp3',
        ])->save();
        Storage::disk('videos')->put('voice/one.mp3', 'audio');
        $voice = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, '/bulk-approve/preview'), [
                'kind' => 'scene_audio',
                'scope' => ['reel_uuid' => $f['reel']->uuid],
                'ids' => [$audio->uuid],
            ])
            ->assertOk()
            ->json('data');
        $this->assertTrue($voice['items'][0]['eligible']);
        $this->assertNull($voice['next_paid_step']);

        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')
            ->postJson($this->url($f, '/bulk-approve'), [
                'kind' => 'character_reference',
                'ids' => [$picture->uuid],
                'selection_hash' => $preview['selection_hash'],
            ])
            ->assertForbidden();
        $this->assertContains('throttle:60,1', Route::getRoutes()->getByName('api.v1.story.projects.bulk-approve.preview')->gatherMiddleware());
        $this->assertContains('throttle:30,1', Route::getRoutes()->getByName('api.v1.story.projects.bulk-approve.store')->gatherMiddleware());
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

    private function extraUnit(StoryProductionUnit $unit): StoryProductionUnit
    {
        $created = new StoryProductionUnit;
        $created->forceFill([
            'story_production_plan_scene_id' => $unit->story_production_plan_scene_id,
            'sequence' => StoryProductionUnit::query()->where('story_production_plan_scene_id', $unit->story_production_plan_scene_id)->max('sequence') + 1,
            'start_second' => ((int) StoryProductionUnit::query()->where('story_production_plan_scene_id', $unit->story_production_plan_scene_id)->max('sequence') + 1) * 100,
            'duration_seconds' => 10,
        ])->save();

        return $created;
    }

    private function version(StoryProductionUnit $unit, string $path, string $status = 'pending_review', int $number = 1): StoryProductionUnitVersion
    {
        Storage::disk('videos')->put($path, 'clip-bytes');
        $version = new StoryProductionUnitVersion;
        $version->forceFill([
            'story_production_unit_id' => $unit->id,
            'version_number' => $number,
            'review_status' => $status,
            'requested_duration_seconds' => 10,
            'produced_duration_seconds' => 10,
            'disk' => 'videos',
            'path' => $path,
            'mime' => 'video/mp4',
            'size' => 10,
        ])->save();

        return $version->fresh();
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function url(array $f, string $suffix): string
    {
        return '/api/v1/story/projects/'.$f['project']->uuid.$suffix;
    }
}
