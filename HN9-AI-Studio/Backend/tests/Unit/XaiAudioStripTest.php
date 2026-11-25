<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Story\Media\StoryMediaOverlay;
use App\Story\Media\StoryMediaSegment;
use App\Story\Media\StoryMediaToolkit;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class XaiAudioStripTest extends TestCase
{
    public function test_strip_drops_audio_and_a_muted_assembly_keeps_the_voiceover_input(): void
    {
        Storage::fake('videos');
        Storage::fake('voice');
        Storage::disk('videos')->put('clip.mp4', 'video-with-audio');
        Storage::disk('voice')->put('voice.mp3', 'voiceover');

        Process::fake(function ($process) {
            $command = $process->command;
            $joined = implode(' ', array_map('strval', $command));
            if (str_contains($joined, '-version')) {
                return Process::result(output: 'ffmpeg version test');
            }
            if (in_array('-show_entries', $command, true)) {
                return Process::result(output: json_encode([
                    'streams' => [
                        ['codec_type' => 'video', 'width' => 320, 'height' => 180],
                        ['codec_type' => 'audio'],
                    ],
                    'format' => ['duration' => '2.0'],
                ], JSON_THROW_ON_ERROR));
            }
            $out = $command[array_key_last($command)] ?? null;
            if (is_string($out) && ! str_starts_with($out, '-')) {
                file_put_contents($out, 'media-bytes');
            }

            return Process::result();
        });

        $toolkit = new StoryMediaToolkit;
        $stripped = storage_path('app/xai-strip-test.mp4');
        $toolkit->stripAudio(storage_path('app/xai-source.mp4'), $stripped);
        Process::assertRan(fn ($process): bool => in_array('-an', $process->command, true));

        $output = 'renders/muted.mp4';
        $toolkit->compose(
            [new StoryMediaSegment(disk: 'videos', path: 'clip.mp4', muteSourceAudio: true)],
            [new StoryMediaOverlay(disk: 'voice', path: 'voice.mp3')],
            $output,
        );

        Process::assertRan(function ($process): bool {
            $joined = implode(' ', array_map('strval', $process->command));

            return str_contains($joined, 'anullsrc') && str_contains($joined, 'o0.mp3');
        });
        Storage::disk('videos')->assertExists($output);
    }
}
