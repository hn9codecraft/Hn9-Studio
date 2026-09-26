<?php

declare(strict_types=1);

namespace App\Story\Video;

use App\Story\Exceptions\StoryVideoEngineException;

/**
 * Splits a requested duration into provider-supported units of at most 30 seconds.
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

        $unit = max($choices);
        $count = (int) ceil($requestedSeconds / $unit);

        return array_fill(0, $count, $unit);
    }
}
