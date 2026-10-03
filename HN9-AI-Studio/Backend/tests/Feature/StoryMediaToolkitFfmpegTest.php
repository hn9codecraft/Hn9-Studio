<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Story\Media\StoryMediaException;
use App\Story\Media\StoryMediaOverlay;
use App\Story\Media\StoryMediaSegment;
use App\Story\Media\StoryMediaToolkit;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Runs the real FFmpeg pipeline on tiny generated clips. Skipped when FFmpeg
 * is not installed on the machine running the tests.
 */
final class StoryMediaToolkitFfmpegTest extends TestCase
{
    private StoryMediaToolkit $media;

    protected function setUp(): void
    {
        parent::setUp();

        $this->media = new StoryMediaToolkit;
        if (! $this->media->available()) {
            $this->markTestSkipped('FFmpeg is not installed.');
        }
        Storage::fake('videos');
        Storage::fake('voice');
    }

    public function test_parts_are_joined_and_trimmed_to_the_scene_length(): void
    {
        $this->clip('videos', 'parts/one.mp4', 2, 'red', withAudio: true);
        $this->clip('videos', 'parts/two.mp4', 2, 'blue', withAudio: false);

        $file = $this->media->join(
            [['disk' => 'videos', 'path' => 'parts/one.mp4'], ['disk' => 'videos', 'path' => 'parts/two.mp4']],
            3.0,
            'joined/scene.mp4',
            '9:16',
        );

        $this->assertSame('videos', $file->disk);
        $this->assertTrue(Storage::disk('videos')->exists('joined/scene.mp4'));
        $this->assertEqualsWithDelta(3.0, $file->durationSeconds, 0.25);
        $probe = $this->probeStored('joined/scene.mp4');
        $this->assertSame([720, 1280], [$probe['width'], $probe['height']]);
        $this->assertTrue($probe['audio']);
        $this->assertSame(hash('sha256', (string) Storage::disk('videos')->get('joined/scene.mp4')), $file->checksum);
    }

    public function test_timeline_with_a_dissolve_and_narration_builds_one_playable_file(): void
    {
        $this->clip('videos', 'scenes/a.mp4', 2, 'green', withAudio: true);
        $this->clip('videos', 'scenes/b.mp4', 2, 'yellow', withAudio: true);
        $this->tone('voice', 'sound/narration.m4a', 1);

        $file = $this->media->compose(
            [
                new StoryMediaSegment('videos', 'scenes/a.mp4', 0.0, 1.5),
                new StoryMediaSegment('videos', 'scenes/b.mp4', 0.0, null, 'dissolve', 0.5),
            ],
            [new StoryMediaOverlay('voice', 'sound/narration.m4a', 1.0)],
            'renders/final.mp4',
        );

        $this->assertEqualsWithDelta(3.0, $file->durationSeconds, 0.25);
        $probe = $this->probeStored('renders/final.mp4');
        $this->assertSame([1280, 720], [$probe['width'], $probe['height']]);
        $this->assertTrue($probe['audio']);
        $this->assertSame([], glob(storage_path('app/story-media/*')) ?: []);
    }

    public function test_a_damaged_clip_fails_without_writing_an_output(): void
    {
        Storage::disk('videos')->put('scenes/broken.mp4', 'not a video');

        try {
            $this->media->compose([new StoryMediaSegment('videos', 'scenes/broken.mp4')], [], 'renders/broken.mp4');
            $this->fail('A damaged clip should not build.');
        } catch (StoryMediaException $exception) {
            $this->assertSame(StoryMediaException::FAILED, $exception->errorCode());
        }

        $this->assertFalse(Storage::disk('videos')->exists('renders/broken.mp4'));
    }

    public function test_sources_outside_the_private_disks_are_refused(): void
    {
        $this->expectException(StoryMediaException::class);
        $this->media->compose([new StoryMediaSegment('public', 'x.mp4')], [], 'renders/x.mp4');
    }

    private function clip(string $disk, string $path, int $seconds, string $color, bool $withAudio): void
    {
        $target = Storage::disk($disk)->path($path);
        @mkdir(dirname($target), 0777, true);
        $args = [config('story_video.ffmpeg.binary'), '-hide_banner', '-y', '-f', 'lavfi', '-i', "color=c={$color}:s=320x240:d={$seconds}:r=24"];
        if ($withAudio) {
            $args = [...$args, '-f', 'lavfi', '-i', "sine=frequency=440:duration={$seconds}", '-c:a', 'aac'];
        }
        $args = [...$args, '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-shortest', $target];
        $this->assertTrue(Process::timeout(60)->run($args)->successful(), 'Could not create a test clip.');
    }

    private function tone(string $disk, string $path, int $seconds): void
    {
        $target = Storage::disk($disk)->path($path);
        @mkdir(dirname($target), 0777, true);
        $result = Process::timeout(60)->run([
            config('story_video.ffmpeg.binary'), '-hide_banner', '-y', '-f', 'lavfi', '-i', "sine=frequency=660:duration={$seconds}", '-c:a', 'aac', $target,
        ]);
        $this->assertTrue($result->successful(), 'Could not create a test tone.');
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
            'audio' => collect($streams)->contains('codec_type', 'audio'),
        ];
    }
}
