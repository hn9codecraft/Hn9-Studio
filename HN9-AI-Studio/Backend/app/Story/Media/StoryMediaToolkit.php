<?php

declare(strict_types=1);

namespace App\Story\Media;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * FFmpeg-backed joining and building of private story media. Sources are
 * copied from their disks into a scratch folder, so cloud disks work the same
 * as local ones; the result is written back to the private videos disk.
 * Nothing here contacts a provider.
 */
class StoryMediaToolkit
{
    private ?bool $available = null;

    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            $ffmpeg = Process::timeout(15)->run([$this->ffmpeg(), '-hide_banner', '-version']);
            $ffprobe = Process::timeout(15)->run([$this->ffprobe(), '-hide_banner', '-version']);
            $this->available = $ffmpeg->successful() && $ffprobe->successful();
        } catch (Throwable) {
            $this->available = false;
        }

        return $this->available;
    }

    /**
     * Joins scene parts end to end and trims the result to the scene length.
     *
     * @param  list<array{disk: string, path: string}>  $parts
     */
    public function join(array $parts, ?float $targetSeconds, string $outputPath, string $aspectRatio = '16:9'): StoryMediaFile
    {
        $segments = array_map(
            static fn (array $part): StoryMediaSegment => new StoryMediaSegment($part['disk'], $part['path']),
            $parts,
        );

        return $this->compose($segments, [], $outputPath, $aspectRatio, $targetSeconds);
    }

    /**
     * Builds one MP4 from picture clips (with cuts, dissolves or fades) and
     * sound clips mixed underneath.
     *
     * @param  list<StoryMediaSegment>  $segments
     * @param  list<StoryMediaOverlay>  $overlays
     */
    public function compose(
        array $segments,
        array $overlays,
        string $outputPath,
        string $aspectRatio = '16:9',
        ?float $maxSeconds = null,
    ): StoryMediaFile {
        if ($segments === []) {
            throw StoryMediaException::failed('There are no picture clips to build.');
        }
        if (! $this->available()) {
            throw StoryMediaException::unavailable();
        }
        if (str_contains($outputPath, '..') || str_contains($outputPath, '://')) {
            throw StoryMediaException::failed('The output location is not valid.');
        }

        $scratch = storage_path('app/story-media/'.Str::uuid()->toString());
        File::ensureDirectoryExists($scratch);

        try {
            [$width, $height] = $this->frameSize($aspectRatio);
            $fps = max(1, (int) config('story_video.ffmpeg.fps', 24));
            $args = [$this->ffmpeg(), '-hide_banner', '-nostdin', '-y'];
            $filters = [];
            $input = 0;
            $total = 0.0;
            $video = null;
            $audio = null;

            foreach ($segments as $index => $segment) {
                $local = $this->copyLocal($segment->disk, $segment->path, $scratch, 'v'.$index);
                $probe = $this->probe($local);
                $in = max(0.0, $segment->inSeconds);
                $out = $segment->outSeconds === null ? $probe['duration'] : min($segment->outSeconds, $probe['duration']);
                $length = $out - $in;
                if ($length <= 0.04) {
                    throw StoryMediaException::failed('A clip is shorter than one frame after trimming.');
                }

                $args = [...$args, '-i', $local];
                $videoInput = $input++;
                $filters[] = sprintf(
                    '[%d:v]trim=start=%s:end=%s,setpts=PTS-STARTPTS,scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1,fps=%d,format=yuv420p[v%d]',
                    $videoInput, $this->num($in), $this->num($out), $width, $height, $width, $height, $fps, $index,
                );

                if ($probe['has_audio']) {
                    $filters[] = sprintf(
                        '[%d:a]atrim=start=%s:end=%s,asetpts=PTS-STARTPTS,aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo[a%d]',
                        $videoInput, $this->num($in), $this->num($out), $index,
                    );
                } else {
                    $args = [...$args, '-f', 'lavfi', '-t', $this->num($length), '-i', 'anullsrc=channel_layout=stereo:sample_rate=48000'];
                    $silent = $input++;
                    $filters[] = sprintf('[%d:a]aformat=sample_fmts=fltp:channel_layouts=stereo[a%d]', $silent, $index);
                }

                if ($video === null) {
                    $video = 'v'.$index;
                    $audio = 'a'.$index;
                    $total = $length;

                    continue;
                }

                $fade = in_array($segment->transition, ['dissolve', 'fade'], true)
                    ? min($segment->transitionSeconds, $total - 0.05, $length - 0.05)
                    : 0.0;
                if ($fade >= 0.04) {
                    $filters[] = sprintf(
                        '[%s][v%d]xfade=transition=%s:duration=%s:offset=%s[vx%d]',
                        $video, $index, $segment->transition === 'fade' ? 'fadeblack' : 'fade',
                        $this->num($fade), $this->num($total - $fade), $index,
                    );
                    $filters[] = sprintf('[%s][a%d]acrossfade=d=%s[ax%d]', $audio, $index, $this->num($fade), $index);
                    $total += $length - $fade;
                } else {
                    $filters[] = sprintf('[%s][v%d]concat=n=2:v=1:a=0[vx%d]', $video, $index, $index);
                    $filters[] = sprintf('[%s][a%d]concat=n=2:v=0:a=1[ax%d]', $audio, $index, $index);
                    $total += $length;
                }
                $video = 'vx'.$index;
                $audio = 'ax'.$index;
            }

            $mix = [$audio];
            foreach ($overlays as $index => $overlay) {
                $local = $this->copyLocal($overlay->disk, $overlay->path, $scratch, 'o'.$index);
                $start = max(0.0, $overlay->startSeconds);
                if ($start >= $total) {
                    continue;
                }
                $args = [...$args, '-i', $local];
                $trim = $overlay->outSeconds !== null
                    ? sprintf('atrim=start=%s:end=%s,', $this->num(max(0.0, $overlay->inSeconds)), $this->num($overlay->outSeconds))
                    : ($overlay->inSeconds > 0 ? sprintf('atrim=start=%s,', $this->num($overlay->inSeconds)) : '');
                $delay = (int) round($start * 1000);
                $filters[] = sprintf(
                    '[%d:a]%sasetpts=PTS-STARTPTS,aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo,adelay=%d|%d[o%d]',
                    $input++, $trim, $delay, $delay, $index,
                );
                $mix[] = 'o'.$index;
            }
            if (count($mix) > 1) {
                $filters[] = sprintf(
                    '%samix=inputs=%d:duration=first:dropout_transition=0:normalize=0[aout]',
                    implode('', array_map(static fn (string $label): string => '['.$label.']', $mix)),
                    count($mix),
                );
                $audio = 'aout';
            }

            $length = $maxSeconds !== null && $maxSeconds > 0 ? min($total, $maxSeconds) : $total;
            $output = $scratch.DIRECTORY_SEPARATOR.'output.mp4';
            $args = [
                ...$args,
                '-filter_complex', implode(';', $filters),
                '-map', '['.$video.']',
                '-map', '['.$audio.']',
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-r', (string) $fps,
                '-c:a', 'aac', '-b:a', '160k', '-ar', '48000',
                '-movflags', '+faststart',
                '-t', $this->num($length),
                $output,
            ];

            $result = Process::timeout(max(30, (int) config('story_video.ffmpeg.timeout_seconds', 900)))->run($args);
            if (! $result->successful() || ! is_file($output) || filesize($output) === 0) {
                Log::warning('Story media build failed.', ['exit_code' => $result->exitCode(), 'error' => mb_substr($result->errorOutput(), -800)]);
                throw StoryMediaException::failed();
            }

            $duration = $this->probe($output)['duration'];
            if ($duration <= 0) {
                throw StoryMediaException::failed();
            }

            $stream = fopen($output, 'rb');
            if ($stream === false) {
                throw StoryMediaException::failed('The built video could not be saved.');
            }
            try {
                Storage::disk('videos')->put($outputPath, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (! Storage::disk('videos')->exists($outputPath)) {
                throw StoryMediaException::failed('The built video could not be saved.');
            }

            return new StoryMediaFile(
                disk: 'videos',
                path: $outputPath,
                mime: 'video/mp4',
                size: (int) filesize($output),
                checksum: (string) hash_file('sha256', $output),
                durationSeconds: $duration,
            );
        } finally {
            File::deleteDirectory($scratch);
        }
    }

    /**
     * @return array{duration: float, has_audio: bool, has_video: bool}
     */
    public function probe(string $localPath): array
    {
        $result = Process::timeout(60)->run([
            $this->ffprobe(), '-v', 'error',
            '-show_entries', 'format=duration:stream=codec_type',
            '-of', 'json',
            $localPath,
        ]);
        $json = json_decode($result->output(), true);
        if (! $result->successful() || ! is_array($json)) {
            throw StoryMediaException::failed('A clip could not be read. It may be damaged.');
        }

        $types = array_map(
            static fn ($stream): string => is_array($stream) ? (string) ($stream['codec_type'] ?? '') : '',
            (array) ($json['streams'] ?? []),
        );

        return [
            'duration' => (float) ($json['format']['duration'] ?? 0),
            'has_audio' => in_array('audio', $types, true),
            'has_video' => in_array('video', $types, true),
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function frameSize(string $aspectRatio): array
    {
        $base = max(240, (int) config('story_video.ffmpeg.height', 720));

        return match ($aspectRatio) {
            '9:16' => [$base, $this->even($base * 16 / 9)],
            '1:1' => [$base, $base],
            '4:3' => [$this->even($base * 4 / 3), $base],
            '3:4' => [$base, $this->even($base * 4 / 3)],
            '21:9' => [$this->even($base * 21 / 9), $base],
            default => [$this->even($base * 16 / 9), $base],
        };
    }

    private function copyLocal(string $disk, string $path, string $scratch, string $name): string
    {
        if (! in_array($disk, ['videos', 'voice'], true) || $path === '' || str_contains($path, '..') || str_contains($path, '://')) {
            throw StoryMediaException::failed('A source file is not stored privately.');
        }
        if (! Storage::disk($disk)->exists($path)) {
            throw StoryMediaException::failed('A source file is missing.');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'bin';
        $local = $scratch.DIRECTORY_SEPARATOR.$name.'.'.preg_replace('/[^a-z0-9]/', '', $extension);
        $source = Storage::disk($disk)->readStream($path);
        if (! is_resource($source)) {
            throw StoryMediaException::failed('A source file could not be read.');
        }
        $target = fopen($local, 'wb');
        if ($target === false) {
            fclose($source);
            throw StoryMediaException::failed('A source file could not be prepared.');
        }
        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        return $local;
    }

    private function ffmpeg(): string
    {
        return (string) config('story_video.ffmpeg.binary', 'ffmpeg');
    }

    private function ffprobe(): string
    {
        return (string) config('story_video.ffmpeg.ffprobe_binary', 'ffprobe');
    }

    private function num(float $seconds): string
    {
        return rtrim(rtrim(number_format(max(0.0, $seconds), 3, '.', ''), '0'), '.') ?: '0';
    }

    private function even(float $value): int
    {
        $rounded = (int) round($value);

        return $rounded % 2 === 0 ? $rounded : $rounded + 1;
    }
}
