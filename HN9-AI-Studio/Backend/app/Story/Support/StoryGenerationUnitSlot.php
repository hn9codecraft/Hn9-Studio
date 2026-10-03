<?php

declare(strict_types=1);

namespace App\Story\Support;

/**
 * One planned generation unit inside a scene. Offsets are whole seconds from the start of the scene.
 */
final readonly class StoryGenerationUnitSlot
{
    public function __construct(
        public int $sequence,
        public int $startSecond,
        public int $durationSeconds,
    ) {}

    public function endSecond(): int
    {
        return $this->startSecond + $this->durationSeconds;
    }

    /**
     * A remainder unit is the shorter final slot of a scene whose length is not a multiple of the unit size.
     */
    public function isRemainder(int $unitSeconds): bool
    {
        return $this->durationSeconds < $unitSeconds;
    }
}
