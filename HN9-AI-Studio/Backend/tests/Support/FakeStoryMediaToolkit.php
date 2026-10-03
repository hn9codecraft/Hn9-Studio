<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Story\Media\StoryMediaException;
use App\Story\Media\StoryMediaFile;
use App\Story\Media\StoryMediaOverlay;
use App\Story\Media\StoryMediaSegment;
use App\Story\Media\StoryMediaToolkit;
use Illuminate\Support\Facades\Storage;

/**
 * Test double for the FFmpeg toolkit. It records what would be built and
 * writes the concatenated source bytes, so tests can check ordering and
 * storage without FFmpeg installed.
 */
final class FakeStoryMediaToolkit extends StoryMediaToolkit
{
    /** @var list<array{segments: list<StoryMediaSegment>, overlays: list<StoryMediaOverlay>, output: string, aspect: string, max: float|null}> */
    public array $builds = [];

    public function __construct(public bool $installed = true, public bool $fails = false) {}

    public function available(): bool
    {
        return $this->installed;
    }

    public function compose(
        array $segments,
        array $overlays,
        string $outputPath,
        string $aspectRatio = '16:9',
        ?float $maxSeconds = null,
    ): StoryMediaFile {
        if (! $this->installed) {
            throw StoryMediaException::unavailable();
        }
        $this->builds[] = ['segments' => $segments, 'overlays' => $overlays, 'output' => $outputPath, 'aspect' => $aspectRatio, 'max' => $maxSeconds];
        if ($this->fails) {
            throw StoryMediaException::failed();
        }

        $bytes = 'built:';
        foreach ([...$segments, ...$overlays] as $source) {
            if (! Storage::disk($source->disk)->exists($source->path)) {
                throw StoryMediaException::failed('A source file is missing.');
            }
            $bytes .= Storage::disk($source->disk)->get($source->path).'|';
        }
        Storage::disk('videos')->put($outputPath, $bytes);

        return new StoryMediaFile('videos', $outputPath, 'video/mp4', strlen($bytes), hash('sha256', $bytes), (float) ($maxSeconds ?? 8));
    }
}
