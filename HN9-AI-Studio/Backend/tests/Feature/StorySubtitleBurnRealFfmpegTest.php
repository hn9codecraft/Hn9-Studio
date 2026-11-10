<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Story\Media\StoryMediaToolkit;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class StorySubtitleBurnRealFfmpegTest extends TestCase
{
    public function test_a_real_ffmpeg_build_burns_gujarati_subtitles_when_the_font_exists(): void
    {
        $media = $this->app->make(StoryMediaToolkit::class);
        $font = config('story_subtitles.burn.font_file');
        if (! $media->subtitlesAvailable() || ! is_string($font) || ! is_file($font)) {
            $this->markTestSkipped('ffmpeg subtitles filter or the configured subtitle font is not available');
        }

        Storage::fake('videos');
        $source = storage_path('app/m16-lavfi-source.mp4');
        $made = Process::timeout(60)->run([
            (string) config('story_video.ffmpeg.binary', 'ffmpeg'),
            '-hide_banner', '-y',
            '-f', 'lavfi', '-i', 'color=c=black:s=320x180:d=2',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-t', '2',
            $source,
        ]);
        if (! $made->successful() || ! is_file($source)) {
            $this->markTestSkipped('ffmpeg could not build a local color clip');
        }

        try {
            Storage::disk('videos')->put('renders/source.mp4', (string) file_get_contents($source));
            $file = $media->burnSubtitles('videos', 'renders/source.mp4', "1\n00:00:00,000 --> 00:00:02,000\nઆ અમારી સફરની\n", 'renders/burned.mp4', [
                'font_name' => config('story_subtitles.burn.font_name'),
                'font_file' => $font,
                'font_size' => 20,
                'margin_v' => 24,
                'outline' => 2,
                'crf' => 20,
                'preset' => 'veryfast',
                'timeout' => 120,
            ]);
            $this->assertTrue(Storage::disk('videos')->exists($file->path));
            $this->assertEqualsWithDelta(2.0, $file->durationSeconds, 0.1);
        } finally {
            @unlink($source);
        }
    }
}
