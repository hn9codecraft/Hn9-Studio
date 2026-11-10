<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Story\Media\StoryMediaException;
use App\Story\Media\StoryMediaFile;
use App\Story\Media\StoryMediaToolkit;
use App\Story\Models\StoryAudioTiming;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StorySceneAudio;
use App\Story\Models\StorySubtitleTrack;
use App\Story\Models\StoryTimeline;
use App\Story\Models\StoryVideoGenerationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsProductionPlanFixtures;
use Tests\TestCase;

final class StorySubtitleApiTest extends TestCase
{
    use BuildsProductionPlanFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Cache::flush();
        Storage::fake('voice');
        Storage::fake('videos');
        Storage::fake('exports');
        config(['story_subtitles.burn.font_file' => null]);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_approximate_cues_are_labeled_and_downloads_hide_paths(): void
    {
        [$f, $render] = $this->rendered('આ અમારી સફરની શરૂઆત છે। હવે આગળ વધીએ.', 4000);
        StoryAudioTiming::query()->create([
            'story_scene_audio_id' => StorySceneAudio::query()->firstOrFail()->id,
            'duration_ms' => 4000,
            'alignment_source' => 'none',
            'checksum' => 'none',
        ]);

        $created = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, $render))
            ->assertOk()
            ->assertJsonPath('data.timing_mode', 'approximate');
        $again = $this->actingAs($f['user'], 'sanctum')->postJson($this->url($f, $render))->assertOk();
        $this->assertSame($created->json('data.uuid'), $again->json('data.uuid'));
        $this->assertStringContainsString('સફરની', json_encode($created->json('data.cues'), JSON_UNESCAPED_UNICODE));

        $cues = $created->json('data.cues');
        $previous = 0;
        foreach ($cues as $cue) {
            $this->assertGreaterThanOrEqual($previous, $cue['start_ms']);
            $this->assertGreaterThan($cue['start_ms'], $cue['end_ms']);
            $previous = $cue['end_ms'];
        }
        $this->assertGreaterThanOrEqual(3500, $cues[array_key_last($cues)]['end_ms']);

        $body = $created->getContent();
        $this->assertStringNotContainsString('srt_path', $body);
        $this->assertStringNotContainsString('subtitles/', $body);

        $slug = $f['project']->slug;
        $short = substr($render->uuid, 0, 8);
        $this->actingAs($f['user'], 'sanctum')
            ->get($this->url($f, $render).'/'.$created->json('data.uuid').'/file?format=srt')
            ->assertOk()
            ->assertHeader('content-type', 'application/x-subrip; charset=utf-8')
            ->assertHeader('content-disposition', 'attachment; filename="'.$slug.'-'.$short.'.srt"');
        $this->actingAs($f['user'], 'sanctum')
            ->get($this->url($f, $render).'/'.$created->json('data.uuid').'/file?format=vtt')
            ->assertOk()
            ->assertHeader('content-type', 'text/vtt; charset=utf-8');

        $language = trim((string) ($f['workspace']->bible?->language ?? ''));
        $this->assertSame($language === '' ? 'und' : $language, $created->json('data.language'));
    }

    public function test_trims_drop_outside_words_and_mixed_tracks_are_labeled(): void
    {
        $f = $this->productionFixture([10, 10]);
        $exact = $this->audio($f, 0, 'exact.mp3', 'આ અમારી');
        $approx = $this->audio($f, 1, 'approx.mp3', 'Hello there friend');
        $job = StoryVideoGenerationJob::factory()->create([
            'story_workspace_id' => $f['workspace']->id,
            'story_reel_id' => $f['reel']->id,
            'story_scene_id' => $f['scenes'][0]->id,
            'operation_id' => 'exact.mp3',
            'status' => 'completed',
        ]);
        $exact->forceFill(['story_video_generation_job_id' => $job->id])->save();
        Storage::disk('voice')->put('alignments/exact.mp3.json', json_encode([
            'characters' => ['આ', ' ', 'અ', 'મ', 'ા', 'ર', 'ી'],
            'character_start_times_seconds' => [0.2, 0.4, 2.5, 2.6, 2.7, 2.8, 2.9],
            'character_end_times_seconds' => [0.4, 0.5, 2.6, 2.7, 2.8, 2.9, 3.0],
        ], JSON_UNESCAPED_UNICODE));
        StoryAudioTiming::query()->create([
            'story_scene_audio_id' => $approx->id,
            'duration_ms' => 3000,
            'alignment_source' => 'none',
            'checksum' => 'approx',
        ]);
        $render = $this->render($f, [
            ['id' => 'v1', 'position' => 1, 'media_kind' => 'video', 'in_ms' => 0, 'out_ms' => 5000],
            ['id' => 'a1', 'position' => 2, 'media_kind' => 'audio', 'disk' => 'voice', 'path' => 'exact.mp3', 'in_ms' => 2000, 'out_ms' => 4000],
            ['id' => 'v2', 'position' => 3, 'media_kind' => 'video', 'in_ms' => 0, 'out_ms' => 4000],
            ['id' => 'a2', 'position' => 4, 'media_kind' => 'audio', 'disk' => 'voice', 'path' => 'approx.mp3', 'in_ms' => 0, 'out_ms' => 3000],
        ], []);

        $created = $this->actingAs($f['user'], 'sanctum')->postJson($this->url($f, $render))->assertOk();
        $this->assertSame('mixed', $created->json('data.timing_mode'));
        $texts = array_column($created->json('data.cues'), 'text');
        $this->assertNotContains('આ', $texts);
        $this->assertTrue(collect($texts)->contains(fn (string $text): bool => str_contains($text, 'અમારી')));
        foreach ($created->json('data.cues') as $cue) {
            $this->assertLessThanOrEqual(9000, $cue['end_ms']);
        }
    }

    public function test_subtitle_routes_require_the_owner_and_are_throttled(): void
    {
        [$f, $render] = $this->rendered('Hello', 2000);
        $this->getJson($this->url($f, $render))->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson($this->url($f, $render))->assertForbidden();

        $index = app('router')->getRoutes()->getByName('api.v1.story.projects.reels.renders.subtitles.index');
        $store = app('router')->getRoutes()->getByName('api.v1.story.projects.reels.renders.subtitles.store');
        $file = app('router')->getRoutes()->getByName('api.v1.story.projects.reels.renders.subtitles.file');
        $burn = app('router')->getRoutes()->getByName('api.v1.story.projects.reels.renders.subtitles.burn');
        $this->assertContains('throttle:60,1', $index->gatherMiddleware());
        $this->assertContains('throttle:30,1', $store->gatherMiddleware());
        $this->assertContains('throttle:60,1', $file->gatherMiddleware());
        $this->assertTrue(collect($burn->gatherMiddleware())->contains(fn (string $middleware): bool => str_contains($middleware, 'throttle')));
    }

    public function test_burn_creates_a_new_render_and_guards_font_filter_failure_and_lock(): void
    {
        [$f, $render] = $this->rendered('આ અમારી સફરની', 3000);
        StoryAudioTiming::query()->create([
            'story_scene_audio_id' => StorySceneAudio::query()->firstOrFail()->id,
            'duration_ms' => 3000,
            'alignment_source' => 'none',
            'checksum' => 'burn',
        ]);
        Storage::disk('videos')->put($render->path, 'source-video');
        $trackId = $this->actingAs($f['user'], 'sanctum')->postJson($this->url($f, $render))->assertOk()->json('data.uuid');
        $sourcePath = $render->path;
        $sourceChecksum = $render->checksum;

        $media = new FakeSubtitleMedia;
        $this->app->instance(StoryMediaToolkit::class, $media);
        $burned = $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, $render).'/'.$trackId.'/burn')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertStringNotContainsString('renders/', $burned->getContent());
        $this->assertStringNotContainsString('સફરની', implode(' ', $media->command));
        $this->assertStringContainsString('subs.srt', implode(' ', $media->command));
        $this->assertStringContainsString('fontsdir=fonts', implode(' ', $media->command));
        $this->assertStringNotContainsString(':\\', implode(' ', $media->command));
        $this->assertNotSame($render->uuid, $burned->json('data.id'));
        $render->refresh();
        $this->assertSame('completed', $render->status);
        $this->assertSame($sourcePath, $render->path);
        $this->assertSame($sourceChecksum, $render->checksum);
        $track = StorySubtitleTrack::query()->where('uuid', $trackId)->firstOrFail();
        $this->assertNotNull($track->burned_render_id);
        $this->assertNotSame($render->id, $track->burned_render_id);

        config(['story_subtitles.burn.font_file' => 'C:/missing/Nirmala.ttc']);
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, $render).'/'.$trackId.'/burn')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_subtitle_font_missing');

        config(['story_subtitles.burn.font_file' => null]);
        $media->filters = false;
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, $render).'/'.$trackId.'/burn')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_subtitle_burn_unavailable');

        $media->filters = true;
        $media->fail = true;
        $before = StoryFinalRender::query()->count();
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, $render).'/'.$trackId.'/burn')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'story_subtitle_burn_failed')
            ->assertJsonPath('message', 'The subtitled video could not be built.');
        $failed = StoryFinalRender::query()->where('status', 'failed')->firstOrFail();
        $this->assertSame('The subtitled video could not be built.', $failed->error_message);
        $this->assertStringNotContainsString('Users', (string) $failed->error_message);
        $this->assertSame($before + 1, StoryFinalRender::query()->count());
        foreach (Storage::disk('videos')->allFiles() as $file) {
            $this->assertStringNotContainsString('partial', $file);
        }
        $render->refresh();
        $this->assertSame($sourcePath, $render->path);

        $media->fail = false;
        $lock = Cache::lock('story-subtitle-burn:'.$f['reel']->id, 60);
        $this->assertTrue($lock->get());
        try {
            $this->actingAs($f['user'], 'sanctum')
                ->postJson($this->url($f, $render).'/'.$trackId.'/burn')
                ->assertStatus(409)
                ->assertJsonPath('error_code', 'story_subtitle_burn_in_progress');
        } finally {
            $lock->release();
        }

        StoryFinalRender::query()->create([
            'story_timeline_id' => $render->story_timeline_id,
            'story_reel_id' => $render->story_reel_id,
            'status' => 'queued',
            'timeline_version' => 1,
            'timeline_snapshot' => [],
        ]);
        $this->actingAs($f['user'], 'sanctum')
            ->postJson($this->url($f, $render).'/'.$trackId.'/burn')
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'story_render_in_progress');
    }

    /**
     * @return array{0: array<string, mixed>, 1: StoryFinalRender}
     */
    private function rendered(string $prompt, int $durationMs): array
    {
        $f = $this->productionFixture([10]);
        $this->audio($f, 0, 'speech.mp3', $prompt);

        return [$f, $this->render($f, [
            ['id' => 'v1', 'position' => 1, 'media_kind' => 'video', 'in_ms' => 0, 'out_ms' => $durationMs],
            ['id' => 'a1', 'position' => 2, 'media_kind' => 'audio', 'disk' => 'voice', 'path' => 'speech.mp3', 'in_ms' => 0, 'out_ms' => $durationMs],
        ], [])];
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function audio(array $f, int $index, string $path, string $prompt): StorySceneAudio
    {
        Storage::disk('voice')->put($path, 'audio');

        return StorySceneAudio::query()->create([
            'story_workspace_id' => $f['workspace']->id,
            'story_scene_id' => $f['scenes'][$index]->id,
            'role' => 'narration',
            'status' => 'completed',
            'version_number' => 1,
            'prompt' => $prompt,
            'disk' => 'voice',
            'path' => $path,
            'mime' => 'audio/mpeg',
            'checksum' => hash('sha256', $path),
        ]);
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  list<array<string, mixed>>  $clips
     * @param  list<array<string, mixed>>  $transitions
     */
    private function render(array $f, array $clips, array $transitions): StoryFinalRender
    {
        $timeline = StoryTimeline::query()->create(['story_reel_id' => $f['reel']->id]);

        return StoryFinalRender::query()->create([
            'story_timeline_id' => $timeline->id,
            'story_reel_id' => $f['reel']->id,
            'status' => 'completed',
            'review_status' => 'draft',
            'timeline_version' => 1,
            'timeline_snapshot' => ['clips' => $clips, 'transitions' => $transitions],
            'disk' => 'videos',
            'path' => 'renders/source.mp4',
            'mime' => 'video/mp4',
            'checksum' => 'source-sum',
        ]);
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function url(array $f, StoryFinalRender $render): string
    {
        return '/api/v1/story/projects/'.$f['project']->uuid.'/reels/'.$f['reel']->uuid.'/renders/'.$render->uuid.'/subtitles';
    }
}

final class FakeSubtitleMedia extends StoryMediaToolkit
{
    public array $command = [];

    public bool $filters = true;

    public bool $fail = false;

    public function available(): bool
    {
        return true;
    }

    public function subtitlesAvailable(): bool
    {
        return $this->filters;
    }

    public function probe(string $localPath): array
    {
        return [
            'duration' => 10,
            'has_audio' => true,
            'has_video' => true,
            'width' => 16,
            'height' => 9,
        ];
    }

    public function burnSubtitles(string $disk, string $path, string $srtContent, string $outputPath, array $style): StoryMediaFile
    {
        if ($this->fail) {
            throw StoryMediaException::failed('C:\\Users\\secret ffmpeg -i in.mp4 -vf subtitles=subs.srt');
        }
        $this->command = $this->subtitleBurnCommand($style);
        Storage::disk('videos')->put($outputPath, 'burned-video');

        return new StoryMediaFile('videos', $outputPath, 'video/mp4', 12, hash('sha256', 'burned-video'), 10, 16, 9, true, true);
    }
}
