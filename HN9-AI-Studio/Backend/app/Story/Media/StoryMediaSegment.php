<?php

declare(strict_types=1);

namespace App\Story\Media;

/**
 * One picture clip on the output track, in seconds.
 */
final readonly class StoryMediaSegment
{
    public function __construct(
        public string $disk,
        public string $path,
        public float $inSeconds = 0.0,
        public ?float $outSeconds = null,
        public string $transition = 'cut',
        public float $transitionSeconds = 0.0,
        public ?float $expectedSeconds = null,
        public ?float $toleranceSeconds = null,
        public ?string $expectedAspect = null,
    ) {}
}
