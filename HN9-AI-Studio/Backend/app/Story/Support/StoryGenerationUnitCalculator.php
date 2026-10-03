<?php

declare(strict_types=1);

namespace App\Story\Support;

use App\Story\Exceptions\StoryProductionPlanException;

/**
 * The single source of truth for splitting a scene into generation units.
 *
 * A scene keeps whatever length the story gives it. Production splits it into
 * sequential units of UNIT_SECONDS; only the last unit may be shorter. Durations
 * are whole seconds, within the scene limits of StorySceneTimingNormalizer.
 * Provider clip lengths never change this split.
 */
final class StoryGenerationUnitCalculator
{
    public const UNIT_SECONDS = 10;

    public function unitSeconds(): int
    {
        return self::UNIT_SECONDS;
    }

    public function unitCount(int $sceneSeconds): int
    {
        $this->assertValidSceneDuration($sceneSeconds);

        return intdiv($sceneSeconds + self::UNIT_SECONDS - 1, self::UNIT_SECONDS);
    }

    /**
     * @return list<StoryGenerationUnitSlot>
     */
    public function split(int $sceneSeconds): array
    {
        $count = $this->unitCount($sceneSeconds);
        $slots = [];
        for ($index = 0; $index < $count; $index++) {
            $start = $index * self::UNIT_SECONDS;
            $slots[] = new StoryGenerationUnitSlot(
                sequence: $index + 1,
                startSecond: $start,
                durationSeconds: min(self::UNIT_SECONDS, $sceneSeconds - $start),
            );
        }

        $this->assertCoverage($slots, $sceneSeconds);

        return $slots;
    }

    public function assertValidSceneDuration(int $sceneSeconds): void
    {
        $min = StorySceneTimingNormalizer::MIN_DURATION_SECONDS;
        $max = StorySceneTimingNormalizer::MAX_DURATION_SECONDS;
        if ($sceneSeconds < $min || $sceneSeconds > $max) {
            throw StoryProductionPlanException::invalidSceneDuration(
                "Scene length must be between {$min} and {$max} seconds.",
            );
        }
    }

    /**
     * Fails unless the slots cover [0, sceneSeconds) exactly: contiguous sequences from 1,
     * no gap, no overlap, every slot longer than zero and no longer than one unit.
     *
     * @param  list<StoryGenerationUnitSlot>  $slots
     */
    public function assertCoverage(array $slots, int $sceneSeconds): void
    {
        $cursor = 0;
        foreach (array_values($slots) as $index => $slot) {
            if ($slot->sequence !== $index + 1
                || $slot->startSecond !== $cursor
                || $slot->durationSeconds < 1
                || $slot->durationSeconds > self::UNIT_SECONDS
            ) {
                throw StoryProductionPlanException::invalidUnitLayout();
            }
            $cursor = $slot->endSecond();
        }

        if ($slots === [] || $cursor !== $sceneSeconds) {
            throw StoryProductionPlanException::invalidUnitLayout();
        }
    }
}
