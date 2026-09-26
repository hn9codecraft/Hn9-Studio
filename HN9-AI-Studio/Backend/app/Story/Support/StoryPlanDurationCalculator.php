<?php

declare(strict_types=1);

namespace App\Story\Support;

use App\Story\Exceptions\StoryPlannerException;

/**
 * Duration segmentation for Project Story plans.
 *
 * Decision: 30 seconds is the target generation unit. Durations that are not
 * divisible by 30 produce a final shorter scene (remainder_strategy =
 * final_short_scene). Exact multiples use remainder_strategy = exact.
 */
final class StoryPlanDurationCalculator
{
    public const UNIT_SECONDS = 30;

    public const MIN_SECONDS = 30;

    public const MAX_SECONDS = 3600;

    /**
     * @return array{
     *     total_duration_seconds: int,
     *     scene_duration_target_seconds: int,
     *     scene_count: int,
     *     remainder_strategy: string,
     *     segments: list<array{sequence: int, start_second: int, end_second: int, duration_seconds: int}>
     * }
     */
    public function calculate(int $totalSeconds): array
    {
        if ($totalSeconds < self::MIN_SECONDS) {
            throw StoryPlannerException::invalidDuration(
                'Requested duration must be at least '.self::MIN_SECONDS.' seconds.',
            );
        }

        if ($totalSeconds > self::MAX_SECONDS) {
            throw StoryPlannerException::invalidDuration(
                'Requested duration must not exceed '.self::MAX_SECONDS.' seconds.',
            );
        }

        $full = intdiv($totalSeconds, self::UNIT_SECONDS);
        $remainder = $totalSeconds % self::UNIT_SECONDS;
        $strategy = $remainder === 0 ? 'exact' : 'final_short_scene';
        $segments = [];
        $cursor = 0;
        $sequence = 1;

        for ($i = 0; $i < $full; $i++) {
            $end = $cursor + self::UNIT_SECONDS;
            $segments[] = [
                'sequence' => $sequence++,
                'start_second' => $cursor,
                'end_second' => $end,
                'duration_seconds' => self::UNIT_SECONDS,
            ];
            $cursor = $end;
        }

        if ($remainder > 0) {
            $end = $cursor + $remainder;
            $segments[] = [
                'sequence' => $sequence,
                'start_second' => $cursor,
                'end_second' => $end,
                'duration_seconds' => $remainder,
            ];
        }

        return [
            'total_duration_seconds' => $totalSeconds,
            'scene_duration_target_seconds' => self::UNIT_SECONDS,
            'scene_count' => count($segments),
            'remainder_strategy' => $strategy,
            'segments' => $segments,
        ];
    }
}
