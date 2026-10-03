<?php

declare(strict_types=1);

namespace App\Story\Media;

/**
 * A sound clip mixed under the picture track, starting at a point in the output.
 */
final readonly class StoryMediaOverlay
{
    public function __construct(
        public string $disk,
        public string $path,
        public float $startSeconds = 0.0,
        public float $inSeconds = 0.0,
        public ?float $outSeconds = null,
    ) {}
}
