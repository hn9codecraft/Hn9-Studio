<?php

declare(strict_types=1);

namespace App\Story\Video;

use App\Story\Exceptions\StoryVideoEngineException;

/**
 * Splits one provider request into provider-supported clip lengths of at most 30 seconds.
 * This is provider chunking; production generation units come from StoryGenerationUnitCalculator.
 */
final class StoryVideoUnitPlanner
{
    public const MAX_UNIT_SECONDS = 30;

    /**
     * @param  list<int>  $supported
     * @return list<int>
     */
    public function units(int $requestedSeconds, array $supported): array
    {
        $choices = array_values(array_filter(
            $supported,
            static fn (int $seconds): bool => $seconds > 0 && $seconds <= self::MAX_UNIT_SECONDS,
        ));

        if ($choices === [] || $requestedSeconds < 1) {
            throw StoryVideoEngineException::invalidInput('Duration is not supported by the selected video provider.');
        }

        if (in_array($requestedSeconds, $choices, true)) {
            return [$requestedSeconds];
        }

        sort($choices);
        $max = max($choices);
        $count = max(1, (int) ceil($requestedSeconds / $max));
        $base = intdiv($requestedSeconds, $count);
        $remainder = $requestedSeconds % $count;

        // Even parts, each rounded up to the nearest length the provider offers.
        $units = [];
        for ($index = 0; $index < $count; $index++) {
            $wanted = $base + ($index < $remainder ? 1 : 0);
            $pick = $max;
            foreach ($choices as $choice) {
                if ($choice >= $wanted) {
                    $pick = $choice;
                    break;
                }
            }
            $units[] = $pick;
        }

        return $units;
    }
}
