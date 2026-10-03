<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Enums\StoryReviewStatus;
use App\Story\Media\StoryMediaException;
use App\Story\Media\StoryMediaFile;
use App\Story\Media\StoryMediaToolkit;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionPlanScene;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryProductionUnitVersion;
use App\Story\Models\StorySceneAssembly;
use App\Story\Services\StorySceneAssemblyService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\TestCase;

class StorySceneAssemblyTest extends TestCase
{
    use BuildsProductionPlanFixtures;
    use RefreshDatabase;

    private string $ffmpeg = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ffmpeg = (string) config('story_video.ffmpeg.binary');
        if (! app(StoryMediaToolkit::class)->available()) {
            $this->markTestSkipped('FFmpeg is not available in this environment.');
        }

        Storage::fake('videos');
        config([
            'story_video.ffmpeg.height' => 240,
            'story_video.queue.enabled' => false,
        ]);
        Http::fake();
    }

    public function test_selected_unit_videos_become_one_scene_video(): void
    {
        [$user, $project, $plan, $scene] = $this->readyScene(10, [10]);

        $response = $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene), [])
            ->assertCreated()
            ->assertJsonPath('data.assembly.status', 'completed')
            ->assertJsonPath('data.assembly.version', 'A')
            ->assertJsonPath('data.assembly.duration_seconds', 10)
            ->assertJsonPath('data.assembly.output_available', true);

        $this->assertEqualsWithDelta(10.0, (float) $response->json('data.assembly.output_duration_seconds'), 0.5);
        $this->assertSame(1, StorySceneAssembly::query()->count());
        $this->assertSame(1, StoryProductionUnit::query()->count());
        Storage::disk('videos')->assertExists((string) StorySceneAssembly::query()->value('path'));
        $this->assertSame([], glob(storage_path('app/story-media/*')) ?: []);
        Http::assertNothingSent();
    }

    public function test_a_47_second_scene_keeps_the_7_second_remainder_in_order(): void
    {
        [$user, $project, $plan, $scene] = $this->readyScene(47, [10, 10, 10, 10, 7]);

        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))
            ->assertCreated()
            ->assertJsonPath('data.assembly.duration_seconds', 47);

        $assembly = StorySceneAssembly::query()->firstOrFail();
        $this->assertEqualsWithDelta(47.0, (float) $assembly->duration_seconds, 0.5);
        $snapshot = $assembly->snapshot['units'];
        $this->assertSame([1, 2, 3, 4, 5], array_column($snapshot, 'sequence'));
        $this->assertSame([10, 10, 10, 10, 7], array_column($snapshot, 'duration_seconds'));
    }

    public function test_standard_scene_lengths_match_their_unit_slots(): void
    {
        foreach ([[30, [10, 10, 10]], [40, [10, 10, 10, 10]], [50, [10, 10, 10, 10, 10]]] as [$duration, $slots]) {
            [$user, $project, $plan, $scene] = $this->readyScene($duration, $slots);
            $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))
                ->assertCreated()
                ->assertJsonPath('data.assembly.duration_seconds', $duration);
            $this->assertEqualsWithDelta($duration, (float) StorySceneAssembly::query()->where('story_production_plan_scene_id', $scene->id)->value('duration_seconds'), 0.5);
        }
    }

    public function test_a_long_scene_of_twelve_units_stays_within_its_planned_length(): void
    {
        [$user, $project, $plan, $scene] = $this->readyScene(120, array_fill(0, 12, 10));

        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))
            ->assertCreated()
            ->assertJsonPath('data.assembly.duration_seconds', 120);

        $this->assertEqualsWithDelta(120.0, (float) StorySceneAssembly::query()->value('duration_seconds'), 0.5);
        $this->assertSame([], glob(storage_path('app/story-media/*')) ?: []);
    }

    public function test_a_missing_selection_stops_before_ffmpeg(): void
    {
        [$user, $project, $plan, $scene] = $this->readyScene(20, [10, 10]);
        $second = $scene->units()->where('sequence', 2)->firstOrFail();
        $second->forceFill(['selected_version_id' => null])->save();

        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'SCENE_NOT_READY')
            ->assertJsonPath('message', 'Some video parts are not ready yet.');

        $this->assertSame(0, StorySceneAssembly::query()->where('status', 'completed')->count());
        $this->assertSame([], Storage::disk('videos')->allFiles('assemblies'));
    }

    public function test_an_unapproved_or_rework_version_cannot_be_assembled(): void
    {
        foreach ([StoryReviewStatus::PendingReview, StoryReviewStatus::NeedsRework] as $status) {
            [$user, $project, $plan, $scene] = $this->readyScene(10, [10]);
            $scene->units()->firstOrFail()->versions()->firstOrFail()->forceFill(['review_status' => $status])->save();

            $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))
                ->assertStatus(422)
                ->assertJsonPath('message', 'Some video parts are not ready yet.');
        }
    }

    public function test_a_missing_or_empty_or_corrupt_file_does_not_create_a_scene_video(): void
    {
        [$user, $project, $plan, $scene] = $this->readyScene(10, [10]);
        $version = $scene->units()->firstOrFail()->versions()->firstOrFail();
        Storage::disk('videos')->delete($version->path);

        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Some video parts are not ready yet.');

        [$user, $project, $plan, $scene] = $this->readyScene(10, [10]);
        $version = $scene->units()->firstOrFail()->versions()->firstOrFail();
        Storage::disk('videos')->put($version->path, '');
        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))
            ->assertStatus(422);

        [$user, $project, $plan, $scene] = $this->readyScene(10, [10]);
        $version = $scene->units()->firstOrFail()->versions()->firstOrFail();
        Storage::disk('videos')->put($version->path, 'this is not a video');
        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))
            ->assertStatus(422)
            ->assertJsonPath('message', 'A clip could not be read. It may be damaged.');

        $this->assertSame([], Storage::disk('videos')->allFiles('assemblies'));
        $this->assertSame([], glob(storage_path('app/story-media/*')) ?: []);
    }

    public function test_a_clip_outside_the_duration_tolerance_is_rejected(): void
    {
        [$user, $project, $plan, $scene] = $this->readyScene(10, [10]);
        $version = $scene->units()->firstOrFail()->versions()->firstOrFail();
        $this->writeClip($version->path, 3);

        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))
            ->assertStatus(422)
            ->assertJsonPath('message', 'A video part does not match its planned length.');

        $this->assertNull(StorySceneAssembly::query()->value('version_number'));
        $this->assertSame([], Storage::disk('videos')->allFiles('assemblies'));
    }

    public function test_a_clip_with_the_wrong_picture_shape_is_rejected(): void
    {
        [$user, $project, $plan, $scene] = $this->readyScene(10, [10]);
        $version = $scene->units()->firstOrFail()->versions()->firstOrFail();
        $this->writeClip($version->path, 10, 320, 240);

        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))
            ->assertStatus(422)
            ->assertJsonPath('message', 'A video part does not match the picture shape of this scene.');
    }

    public function test_audio_and_silent_clips_still_produce_a_playable_scene(): void
    {
        [$user, $project, $plan, $scene] = $this->readyScene(20, [10, 10], [true, false]);

        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))
            ->assertCreated();

        $assembly = StorySceneAssembly::query()->firstOrFail();
        $probe = $this->probeStored((string) $assembly->path);
        $this->assertTrue($probe['audio']);
        $this->assertGreaterThan(0, $probe['width']);
    }

    public function test_different_frame_rates_are_normalized_into_one_scene(): void
    {
        [$user, $project, $plan, $scene] = $this->readyScene(20, [10, 10], [false, false], [24, 30]);

        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))
            ->assertCreated()
            ->assertJsonPath('data.assembly.duration_seconds', 20);
    }

    public function test_a_changed_selection_creates_a_new_version_and_keeps_the_old_one(): void
    {
        [$user, $project, $plan, $scene] = $this->readyScene(10, [10]);
        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))->assertCreated();
        $first = StorySceneAssembly::query()->firstOrFail();
        $firstPath = (string) $first->path;

        $unit = $scene->units()->firstOrFail();
        $next = $this->version($unit, $this->writeClip('clips/'.Str::uuid().'.mp4', 10), 'B');
        $unit->forceFill(['selected_version_id' => $next->id])->save();

        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))
            ->assertCreated()
            ->assertJsonPath('data.assembly.version', 'B');

        $this->assertSame(2, StorySceneAssembly::query()->count());
        Storage::disk('videos')->assertExists($firstPath);
        $this->assertNotSame($firstPath, StorySceneAssembly::query()->where('version_number', 2)->value('path'));
    }

    public function test_the_same_selection_reuses_one_assembly(): void
    {
        [$user, $project, $plan, $scene] = $this->readyScene(10, [10]);
        $url = $this->assembleUrl($project->uuid, $plan->uuid, $scene);
        $first = $this->actingAs($user, 'sanctum')->postJson($url)->assertCreated()->json('data.assembly.id');
        $this->actingAs($user, 'sanctum')->postJson($url)->assertOk()->assertJsonPath('data.created', false)->assertJsonPath('data.assembly.id', $first);
        $this->assertSame(1, StorySceneAssembly::query()->count());
        $this->assertCount(1, Storage::disk('videos')->allFiles('assemblies'));
    }

    public function test_a_duplicate_insert_reuses_the_winning_assembly(): void
    {
        [$user, $project, $plan, $scene] = $this->readyScene(10, [10]);
        StorySceneAssembly::creating(function (): void {
            static $thrown = false;
            if (! $thrown) {
                $thrown = true;
                throw new UniqueConstraintViolationException('sqlite', 'insert', [], new \RuntimeException('UNIQUE constraint failed: story_scene_assemblies.idempotency_key'));
            }
        });

        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))->assertCreated();
        $this->assertSame(1, StorySceneAssembly::query()->count());
    }

    public function test_a_failed_build_can_be_retried_and_invalid_output_is_removed(): void
    {
        [$user, $project, $plan, $scene] = $this->readyScene(10, [10]);
        $mode = (object) ['invalid' => true];
        $this->app->bind(StoryMediaToolkit::class, function () use ($mode) {
            return new class($mode) extends StoryMediaToolkit
            {
                public function __construct(private object $mode) {}

                public function compose(array $segments, array $overlays, string $outputPath, string $aspectRatio = '16:9', ?float $maxSeconds = null): StoryMediaFile
                {
                    if ($this->mode->invalid) {
                        Storage::disk('videos')->put($outputPath, 'partial');

                        return new StoryMediaFile('videos', $outputPath, 'video/mp4', 7, 'partial', 1.0, 10, 10, true, false);
                    }

                    return parent::compose($segments, $overlays, $outputPath, $aspectRatio, $maxSeconds);
                }
            };
        });

        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))
            ->assertStatus(422)
            ->assertJsonPath('message', 'The finished scene video does not match the planned length.')
            ->assertJsonPath('error_code', 'SCENE_ASSEMBLY_DURATION');

        $failed = StorySceneAssembly::query()->firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertNull($failed->version_number);
        $this->assertNull($failed->path);
        $this->assertSame([], Storage::disk('videos')->allFiles('assemblies'));

        $mode->invalid = false;
        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))->assertOk();
        $this->assertSame(1, StorySceneAssembly::query()->count());
        $this->assertSame('completed', StorySceneAssembly::query()->value('status'));
        $this->assertTrue(ActivityLog::query()->where('action', StorySceneAssemblyService::EVENT_RETRIED)->exists());
    }

    public function test_ffmpeg_failure_leaves_no_scene_video(): void
    {
        [$user, $project, $plan, $scene] = $this->readyScene(10, [10]);
        $this->app->bind(StoryMediaToolkit::class, fn () => new class extends StoryMediaToolkit
        {
            public function compose(array $segments, array $overlays, string $outputPath, string $aspectRatio = '16:9', ?float $maxSeconds = null): StoryMediaFile
            {
                throw StoryMediaException::failed('The video could not be built from these clips.');
            }
        });

        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))
            ->assertStatus(422)
            ->assertJsonPath('message', 'The video could not be built from these clips.');

        $this->assertSame('failed', StorySceneAssembly::query()->value('status'));
        $this->assertSame([], Storage::disk('videos')->allFiles('assemblies'));
        $this->assertSame([], glob(storage_path('app/story-media/*')) ?: []);
    }

    public function test_history_uses_plain_language_and_units_stay_unchanged(): void
    {
        [$user, $project, $plan, $scene] = $this->readyScene(10, [10]);
        $before = $scene->units()->firstOrFail()->only(['sequence', 'duration_seconds', 'start_second']);
        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($project->uuid, $plan->uuid, $scene))->assertCreated();
        $this->assertSame($before, $scene->units()->firstOrFail()->only(['sequence', 'duration_seconds', 'start_second']));

        $history = $this->actingAs($user, 'sanctum')->getJson("/api/v1/story/projects/{$project->uuid}/history")->assertOk()->json('data');
        $rows = array_values(array_filter($history, static fn (array $row): bool => $row['kind'] === 'scene_assembly'));
        $this->assertNotEmpty($rows);
        $encoded = json_encode($rows);
        $this->assertStringNotContainsString('assemblies/', (string) $encoded);
        $this->assertStringNotContainsString('ffmpeg', strtolower((string) $encoded));
        $this->assertTrue(ActivityLog::query()->where('description', 'Scene video is ready')->exists());
    }

    public function test_stale_assembly_is_marked_failed_without_a_second_render(): void
    {
        [$user, $project, $plan, $scene] = $this->readyScene(10, [10]);
        $row = new StorySceneAssembly;
        $row->forceFill([
            'story_workspace_id' => $plan->story_workspace_id,
            'story_production_plan_scene_id' => $scene->id,
            'requested_by' => $user->id,
            'idempotency_key' => 'scene-assembly:stale',
            'status' => 'processing',
            'snapshot' => ['units' => []],
            'expected_duration_seconds' => 10,
            'disk' => 'videos',
            'path' => 'assemblies/stale.mp4',
        ])->save();
        Storage::disk('videos')->put('assemblies/stale.mp4', 'partial');
        StorySceneAssembly::query()->whereKey($row->id)->update(['updated_at' => now()->subHours(2)]);

        $this->assertSame(1, app(StorySceneAssemblyService::class)->recoverStale());
        $row->refresh();
        $this->assertSame('failed', $row->status);
        $this->assertSame('The scene video build was interrupted. You can try again.', $row->error_message);
        Storage::disk('videos')->assertMissing('assemblies/stale.mp4');
    }

    public function test_paths_and_other_projects_cannot_drive_assembly(): void
    {
        [$user, $project, $plan, $scene] = $this->readyScene(10, [10]);
        $url = $this->assembleUrl($project->uuid, $plan->uuid, $scene);
        $this->postJson($url)->assertUnauthorized();

        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')->postJson($url)->assertForbidden();

        $foreign = $this->productionFixture([10]);
        $this->actingAs($user, 'sanctum')->postJson($this->assembleUrl($foreign['project']->uuid, $plan->uuid, $scene))->assertForbidden();
        $this->actingAs($foreign['user'], 'sanctum')->postJson($url)->assertForbidden();
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/production-plans/{$plan->uuid}/scenes/".Str::uuid().'/assemble')
            ->assertNotFound();

        $version = $scene->units()->firstOrFail()->versions()->firstOrFail();
        $original = (string) $version->path;
        $version->forceFill(['path' => 'clips/../../secret.mp4'])->save();
        $this->actingAs($user, 'sanctum')->postJson($url, [
            'path' => $original,
            'command' => 'ffmpeg -i secret.mp4',
        ])->assertStatus(422)->assertJsonPath('message', 'Some video parts are not ready yet.');

        $version->forceFill(['path' => 'clips/a;rm.mp4'])->save();
        $this->actingAs($user, 'sanctum')->postJson($url)->assertStatus(422);
        $this->assertSame(0, StorySceneAssembly::query()->where('status', 'completed')->count());

        $version->forceFill(['path' => $original])->save();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')->postJson($url)->assertCreated();
    }

    /**
     * @param  list<int>  $slots
     * @param  list<bool>|null  $audio
     * @param  list<int>|null  $fps
     * @return array{0: User, 1: Project, 2: StoryProductionPlan, 3: StoryProductionPlanScene}
     */
    private function readyScene(int $duration, array $slots, ?array $audio = null, ?array $fps = null): array
    {
        $fixture = $this->productionFixture([$duration]);
        $plan = app(StoryProductionPlanServiceInterface::class)->createForVersion(
            $fixture['project'],
            $fixture['plan']->uuid,
            $fixture['version']->uuid,
            $fixture['user'],
        )['plan'];
        $scene = StoryProductionPlanScene::query()
            ->where('story_production_plan_id', $plan->id)
            ->with(['units', 'scene'])
            ->firstOrFail();
        $actual = $scene->units->sortBy('sequence')->pluck('duration_seconds')->map(static fn ($value): int => (int) $value)->values()->all();
        $this->assertSame($slots, $actual);
        $user = $fixture['user'];
        $project = $fixture['project'];

        foreach ($scene->units->sortBy('sequence')->values() as $index => $unit) {
            $path = $this->writeClip(
                'clips/'.Str::uuid().'.mp4',
                (int) $unit->duration_seconds,
                320,
                180,
                $audio[$index] ?? false,
                $fps[$index] ?? 24,
            );
            $version = $this->version($unit, $path, 'A');
            $unit->forceFill(['selected_version_id' => $version->id])->save();
        }

        return [$user, $project, $plan->fresh(), $scene->fresh('units')];
    }

    private function version(StoryProductionUnit $unit, string $path, string $letter): StoryProductionUnitVersion
    {
        $version = new StoryProductionUnitVersion;
        $version->forceFill([
            'story_production_unit_id' => $unit->id,
            'version_number' => $letter === 'A' ? 1 : 2,
            'review_status' => StoryReviewStatus::Approved->value,
            'disk' => 'videos',
            'path' => $path,
            'mime' => 'video/mp4',
            'size' => Storage::disk('videos')->size($path),
            'requested_duration_seconds' => (int) $unit->duration_seconds,
            'produced_duration_seconds' => (int) $unit->duration_seconds,
            'provider_key' => 'video.contract',
            'model_key' => 'contract-video',
            'capability' => 'video.generate',
        ])->save();

        return $version;
    }

    /**
     * @return array{width: int, height: int, audio: bool}
     */
    private function probeStored(string $path): array
    {
        $result = Process::timeout(60)->run([
            config('story_video.ffmpeg.ffprobe_binary'), '-v', 'error', '-show_entries', 'stream=codec_type,width,height', '-of', 'json',
            Storage::disk('videos')->path($path),
        ]);
        $streams = (array) (json_decode($result->output(), true)['streams'] ?? []);
        $video = collect($streams)->firstWhere('codec_type', 'video') ?? [];

        return [
            'width' => (int) ($video['width'] ?? 0),
            'height' => (int) ($video['height'] ?? 0),
            'audio' => collect($streams)->contains(static fn ($stream): bool => ($stream['codec_type'] ?? null) === 'audio'),
        ];
    }

    private function writeClip(string $path, int $seconds, int $width = 320, int $height = 180, bool $audio = false, int $fps = 24): string
    {
        $absolute = Storage::disk('videos')->path($path);
        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0777, true);
        }
        $command = [
            $this->ffmpeg, '-y', '-f', 'lavfi', '-i', "color=c=blue:s={$width}x{$height}:r={$fps}:d={$seconds}",
        ];
        if ($audio) {
            $command = [
                ...$command,
                '-f', 'lavfi', '-i', "sine=frequency=440:sample_rate=44100:d={$seconds}",
                '-shortest',
            ];
        }
        $command = [...$command, '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-t', (string) $seconds, $absolute];
        $process = new \Symfony\Component\Process\Process($command);
        $process->setTimeout(60);
        $process->mustRun();

        return $path;
    }

    private function assembleUrl(string $projectUuid, string $planUuid, StoryProductionPlanScene $scene): string
    {
        return "/api/v1/story/projects/{$projectUuid}/production-plans/{$planUuid}/scenes/{$scene->scene->uuid}/assemble";
    }
}
