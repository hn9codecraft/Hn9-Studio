<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Enums\StorySceneStatus;
use App\Story\Media\StoryMediaToolkit;
use App\Story\Models\StoryAudioTiming;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnitVersion;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneAudio;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Support\StoryGenerationUnitCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\TestCase;

final class StoryVoiceTimingTest extends TestCase
{
    use BuildsProductionPlanFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Cache::flush();
        Storage::fake('voice');
        ProbeMediaToolkit::$durations = [];
        $this->app->instance(StoryMediaToolkit::class, new ProbeMediaToolkit);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_preview_proposes_lengths_without_writing_scenes_or_plans(): void
    {
        [$f, $plan] = $this->planned([30, 20]);
        $this->speech($f['scenes'][0], 'narration', 'long.mp3', 12.2, 'Spoken secret');
        $plans = StoryProductionPlan::query()->count();
        $duration = $f['scenes'][0]->duration_seconds;

        $response = $this->actingAs($f['user'], 'sanctum')
            ->getJson($this->url($f, $plan))
            ->assertOk()
            ->assertJsonPath('data.measurable', true)
            ->assertJsonPath('data.drift_warning', true);

        $scene = $response->json('data.scenes.0');
        $this->assertSame(13, $scene['proposed_seconds']);
        $this->assertSame(30, $scene['current_seconds']);
        $this->assertSame([['sequence' => 1, 'duration_seconds' => 10], ['sequence' => 2, 'duration_seconds' => 3]], $scene['units_after']);
        $this->assertSame(50, $response->json('data.total_before'));
        $this->assertSame(33, $response->json('data.total_after'));
        $this->assertSame($plans, StoryProductionPlan::query()->count());
        $this->assertSame($duration, $f['scenes'][0]->refresh()->duration_seconds);
        $this->assertSame(10, StoryGenerationUnitCalculator::UNIT_SECONDS);
    }

    public function test_apply_revises_the_plan_and_normalizes_scene_bounds(): void
    {
        [$f, $plan] = $this->planned([30, 20]);
        $this->speech($f['scenes'][0], 'narration', 'long.mp3', 12.2, 'Spoken secret');

        $response = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, $plan).'/apply', ['round_to' => 1])
            ->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.production_plan.revision', 2)
            ->assertJsonPath('data.previous_plan.revision', 1)
            ->assertJsonPath('data.timeline_stale', true);

        $first = $f['scenes'][0]->refresh();
        $second = $f['scenes'][1]->refresh();
        $this->assertSame(13, $first->duration_seconds);
        $this->assertSame(0, $first->start_second);
        $this->assertSame(13, $first->end_second);
        $this->assertSame(20, $second->duration_seconds);
        $this->assertSame(13, $second->start_second);
        $this->assertSame(33, $second->end_second);

        $next = StoryProductionPlan::query()->where('uuid', $response->json('data.production_plan.uuid'))->firstOrFail();
        $previous = StoryProductionPlan::query()->where('uuid', $plan->uuid)->firstOrFail();
        $this->assertTrue($next->isCurrent());
        $this->assertFalse($previous->isCurrent());
        $this->assertSame('superseded', $previous->status);
        $this->assertSame(10, $next->unit_seconds);
        $this->assertSame([10, 3], $next->scenes->first()->units->pluck('duration_seconds')->all());
        $this->assertSame(0, StoryVideoGenerationJob::query()->count());

        $log = ActivityLog::query()->where('action', 'story.voice_timing.applied')->firstOrFail();
        $encoded = json_encode($log->toArray());
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('long.mp3', $encoded);
        $this->assertStringNotContainsString('Spoken secret', $encoded);
    }

    public function test_rounding_never_undercuts_the_voice_and_rejects_unknown_steps(): void
    {
        [$f, $plan] = $this->planned([30]);
        $this->speech($f['scenes'][0], 'narration', 'voice.mp3', 7.3);

        $round1 = $this->actingAs($f['user'], 'sanctum')->getJson($this->url($f, $plan).'?round_to=1&lead_in_ms=300&tail_ms=500')->assertOk();
        $this->assertSame(9, $round1->json('data.scenes.0.proposed_seconds'));

        Cache::flush();
        $round5 = $this->actingAs($f['user'], 'sanctum')->getJson($this->url($f, $plan).'?round_to=5&lead_in_ms=300&tail_ms=500')->assertOk();
        $this->assertSame(10, $round5->json('data.scenes.0.proposed_seconds'));
        $this->assertGreaterThanOrEqual(8, $round5->json('data.scenes.0.proposed_seconds'));

        $this->actingAs($f['user'], 'sanctum')->getJson($this->url($f, $plan).'?round_to=3')->assertStatus(422);

        config(['story_voice_timing.min_scene_seconds' => 3]);
        Cache::flush();
        Storage::disk('voice')->put('short.mp3', 'short');
        ProbeMediaToolkit::$durations['short.mp3'] = 1.0;
        StorySceneAudio::query()->delete();
        $this->speech($f['scenes'][0], 'narration', 'short.mp3', 1.0);
        $min = $this->actingAs($f['user'], 'sanctum')->getJson($this->url($f, $plan).'?lead_in_ms=0&tail_ms=0')->assertOk();
        $this->assertSame(3, $min->json('data.scenes.0.proposed_seconds'));
        $this->assertGreaterThanOrEqual(1, $min->json('data.scenes.0.proposed_seconds'));
    }

    public function test_speech_selection_uses_the_longer_ready_voice_and_ignores_music(): void
    {
        [$f, $plan] = $this->planned([30, 30]);
        $this->speech($f['scenes'][0], 'narration', 'selected.mp3', 4.0, selected: true);
        $this->speech($f['scenes'][0], 'narration', 'approved.mp3', 9.0, review: 'approved');
        $this->speech($f['scenes'][0], 'dialogue', 'dialogue.mp3', 7.3);
        $this->speech($f['scenes'][0], 'music', 'music.mp3', 20.0);
        $this->speech($f['scenes'][1], 'music', 'only-music.mp3', 12.0);

        $response = $this->actingAs($f['user'], 'sanctum')
            ->getJson($this->url($f, $plan).'?lead_in_ms=300&tail_ms=500')
            ->assertOk();

        $this->assertSame('dialogue', $response->json('data.scenes.0.voice_role'));
        $this->assertSame(9, $response->json('data.scenes.0.proposed_seconds'));
        $this->assertFalse($response->json('data.scenes.1.changed'));
        $this->assertNull($response->json('data.scenes.1.voice_audio_uuid'));
    }

    public function test_selected_audio_wins_over_a_longer_approved_take(): void
    {
        [$f, $plan] = $this->planned([30]);
        $this->speech($f['scenes'][0], 'narration', 'selected.mp3', 4.0, selected: true);
        $this->speech($f['scenes'][0], 'narration', 'approved.mp3', 9.0, review: 'approved');

        $response = $this->actingAs($f['user'], 'sanctum')
            ->getJson($this->url($f, $plan).'?lead_in_ms=0&tail_ms=0')
            ->assertOk();

        $this->assertSame(4, $response->json('data.scenes.0.proposed_seconds'));
        $this->assertEquals(4, $response->json('data.scenes.0.voice_seconds'));
    }

    public function test_apply_does_nothing_when_the_length_already_matches(): void
    {
        [$f, $plan] = $this->planned([9]);
        $this->speech($f['scenes'][0], 'narration', 'voice.mp3', 7.3);

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, $plan).'/apply', ['round_to' => 1, 'lead_in_ms' => 300, 'tail_ms' => 500])
            ->assertOk()
            ->assertJsonPath('data.changed', false);

        $this->assertSame(1, StoryProductionPlan::query()->count());
        $this->assertSame(0, ActivityLog::query()->where('action', 'story.voice_timing.applied')->count());
    }

    public function test_existing_unit_versions_require_confirmation_and_generate_nothing(): void
    {
        [$f, $plan] = $this->planned([30]);
        $this->speech($f['scenes'][0], 'narration', 'voice.mp3', 12.2);
        $unit = $plan->scenes->first()->units->first();
        StoryProductionUnitVersion::query()->forceCreate([
            'story_production_unit_id' => $unit->id,
            'version_number' => 1,
            'requested_duration_seconds' => $unit->duration_seconds,
            'produced_duration_seconds' => $unit->duration_seconds,
            'disk' => 'videos',
            'path' => 'clips/kept.mp4',
        ]);

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, $plan).'/apply', ['round_to' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_voice_timing_requires_regeneration')
            ->assertJsonPath('context.scenes_with_unit_versions', 1);

        $this->assertSame(30, $f['scenes'][0]->refresh()->duration_seconds);
        $this->assertSame(1, StoryProductionPlan::query()->count());

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, $plan).'/apply', ['round_to' => 1, 'accept_regeneration' => true])
            ->assertOk()
            ->assertJsonPath('data.changed', true);

        $this->assertSame(2, StoryProductionPlan::query()->count());
        $this->assertSame(1, StoryProductionUnitVersion::query()->count());
        $this->assertSame($unit->id, StoryProductionUnitVersion::query()->firstOrFail()->story_production_unit_id);
        $this->assertSame(0, StoryVideoGenerationJob::query()->count());
    }

    public function test_non_current_archived_and_processing_voices_are_guarded(): void
    {
        [$f, $plan] = $this->planned([30, 30]);
        $this->speech($f['scenes'][0], 'narration', 'voice.mp3', 12.2);
        $applied = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, $plan).'/apply', ['round_to' => 1])
            ->assertOk();

        $this->actingAs($f['user'], 'sanctum')
            ->getJson($this->url($f, $plan))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_production_plan_not_current');

        $current = StoryProductionPlan::query()->where('uuid', $applied->json('data.production_plan.uuid'))->firstOrFail();
        $f['scenes'][1]->forceFill(['status' => StorySceneStatus::Archived->value])->save();
        $preview = $this->actingAs($f['user'], 'sanctum')->getJson($this->url($f, $current))->assertOk();
        $this->assertNotContains($f['scenes'][1]->uuid, array_column($preview->json('data.scenes'), 'scene_uuid'));

        [$again, $againPlan] = $this->planned([30]);
        StorySceneAudio::query()->create([
            'story_workspace_id' => $again['workspace']->id,
            'story_scene_id' => $again['scenes'][0]->id,
            'role' => 'voice',
            'status' => 'processing',
            'version_number' => 1,
            'prompt' => 'Still recording',
        ]);
        $processing = $this->actingAs($again['user'], 'sanctum')->getJson($this->url($again, $againPlan))->assertOk();
        $this->assertContains('voice_not_ready', $processing->json('data.scenes.0.warnings'));
        $this->assertFalse($processing->json('data.scenes.0.changed'));
        $this->assertSame(30, $again['scenes'][0]->refresh()->duration_seconds);
    }

    public function test_missing_toolkit_does_not_write(): void
    {
        [$f, $plan] = $this->planned([30]);
        $this->speech($f['scenes'][0], 'narration', 'voice.mp3', 12.2);
        $this->app->instance(StoryMediaToolkit::class, new ProbeMediaToolkit(up: false));

        $this->actingAs($f['user'], 'sanctum')
            ->getJson($this->url($f, $plan))
            ->assertOk()
            ->assertJsonPath('data.measurable', false)
            ->assertJsonPath('data.message', 'FFmpeg/ffprobe not found');

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, $plan).'/apply', ['round_to' => 1])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_media_unavailable');

        $this->assertSame(30, $f['scenes'][0]->refresh()->duration_seconds);
        $this->assertSame(1, StoryProductionPlan::query()->count());
        $this->assertSame(0, StoryAudioTiming::query()->count());
    }

    public function test_revise_failure_rolls_back_and_the_lock_holds(): void
    {
        [$f, $plan] = $this->planned([30]);
        $this->speech($f['scenes'][0], 'narration', 'voice.mp3', 12.2);
        $real = $this->app->make(StoryProductionPlanServiceInterface::class);
        $mock = Mockery::mock(StoryProductionPlanServiceInterface::class);
        $mock->shouldReceive('getForProject')->andReturnUsing(
            static fn ($project, $uuid) => $real->getForProject($project, $uuid),
        );
        $mock->shouldReceive('revise')->andThrow(new RuntimeException('forced'));
        $this->app->instance(StoryProductionPlanServiceInterface::class, $mock);

        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, $plan).'/apply', ['round_to' => 1])
            ->assertStatus(500);
        $this->assertSame(30, $f['scenes'][0]->refresh()->duration_seconds);
        $this->assertSame(1, StoryProductionPlan::query()->count());

        $lock = Cache::lock('story-voice-timing:'.$f['workspace']->id, 60);
        $this->assertTrue($lock->get());
        try {
            $this->actingAs($f['user'], 'sanctum')
                ->postJson($this->url($f, $plan).'/apply', ['round_to' => 1])
                ->assertStatus(409)
                ->assertJsonPath('error_code', 'story_voice_timing_in_progress');
        } finally {
            $lock->release();
        }
        $this->assertSame(30, $f['scenes'][0]->refresh()->duration_seconds);
    }

    public function test_the_same_idempotency_key_creates_one_revision(): void
    {
        [$f, $plan] = $this->planned([30]);
        $this->speech($f['scenes'][0], 'narration', 'voice.mp3', 12.2);
        $key = 'timing-once';

        $first = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, $plan).'/apply', ['round_to' => 1, 'idempotency_key' => $key])
            ->assertOk();
        $second = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, $plan).'/apply', ['round_to' => 1, 'idempotency_key' => $key])
            ->assertOk();

        $this->assertSame($first->json('data.production_plan.uuid'), $second->json('data.production_plan.uuid'));
        $this->assertSame(2, StoryProductionPlan::query()->count());
    }

    public function test_voice_timing_routes_require_the_owner_and_are_throttled(): void
    {
        [$f, $plan] = $this->planned([30]);
        $this->getJson($this->url($f, $plan))->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson($this->url($f, $plan))->assertForbidden();

        $route = app('router')->getRoutes()->getByName('api.v1.story.projects.production-plans.voice-timing.show');
        $this->assertNotNull($route);
        $this->assertContains('throttle:60,1', $route->gatherMiddleware());
        $apply = app('router')->getRoutes()->getByName('api.v1.story.projects.production-plans.voice-timing.apply');
        $this->assertNotNull($apply);
        $this->assertContains('throttle:30,1', $apply->gatherMiddleware());
    }

    /**
     * @param  list<int>  $durations
     * @return array{0: array<string, mixed>, 1: StoryProductionPlan}
     */
    private function planned(array $durations): array
    {
        $f = $this->productionFixture($durations);
        $result = app(StoryProductionPlanServiceInterface::class)->createForVersion($f['project'], $f['plan']->uuid, $f['version']->uuid, $f['user']);

        return [$f, $result['plan']];
    }

    private function speech(StoryScene $scene, string $role, string $path, float $seconds, string $prompt = 'Hello', bool $selected = false, ?string $review = null): void
    {
        Storage::disk('voice')->put($path, 'audio-bytes');
        ProbeMediaToolkit::$durations[$path] = $seconds;
        $scene->loadMissing('reel');
        StorySceneAudio::query()->create([
            'story_workspace_id' => $scene->reel->story_workspace_id,
            'story_scene_id' => $scene->id,
            'role' => $role,
            'status' => 'completed',
            'version_number' => 1,
            'review_status' => $review ?? 'pending_review',
            'selected_at' => $selected ? now() : null,
            'prompt' => $prompt,
            'disk' => 'voice',
            'path' => $path,
            'mime' => 'audio/mpeg',
            'checksum' => hash('sha256', $path),
        ]);
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function url(array $f, StoryProductionPlan $plan): string
    {
        return '/api/v1/story/projects/'.$f['project']->uuid.'/production-plans/'.$plan->uuid.'/voice-timing';
    }
}

final class ProbeMediaToolkit extends StoryMediaToolkit
{
    /** @var array<string, float> */
    public static array $durations = [];

    public function __construct(private bool $up = true) {}

    public function available(): bool
    {
        return $this->up;
    }

    public function probe(string $localPath): array
    {
        return [
            'duration' => self::$durations[basename($localPath)] ?? 7.3,
            'has_audio' => true,
            'has_video' => false,
            'width' => 0,
            'height' => 0,
        ];
    }
}
