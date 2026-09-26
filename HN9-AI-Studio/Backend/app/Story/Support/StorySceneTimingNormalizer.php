<?php

declare(strict_types=1);

namespace App\Story\Support;

use App\Story\Enums\StorySceneStatus;
use App\Story\Exceptions\StoryRuntimeException;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use Illuminate\Support\Collection;

/**
 * Normalizes scene sequence and start/end timing for a reel.
 */
final class StorySceneTimingNormalizer
{
    public const DEFAULT_DURATION_SECONDS = 30;

    public const MIN_DURATION_SECONDS = 1;

    public const MAX_DURATION_SECONDS = 3600;

    public function assertValidDuration(int $seconds): void
    {
        if ($seconds < self::MIN_DURATION_SECONDS || $seconds > self::MAX_DURATION_SECONDS) {
            throw StoryRuntimeException::invalidDuration(
                'Scene duration must be between '.self::MIN_DURATION_SECONDS.' and '.self::MAX_DURATION_SECONDS.' seconds.',
            );
        }
    }

    /**
     * Recalculate sequence + timing for active (non-archived) scenes and update reel total.
     *
     * @param  Collection<int, StoryScene>|null  $orderedActiveScenes
     */
    public function normalizeReel(StoryReel $reel, ?Collection $orderedActiveScenes = null): StoryReel
    {
        $scenes = $orderedActiveScenes ?? StoryScene::query()
            ->where('story_reel_id', $reel->id)
            ->where('status', '!=', StorySceneStatus::Archived->value)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $cursor = 0;
        $sequence = 1;

        foreach ($scenes as $scene) {
            $duration = max(self::MIN_DURATION_SECONDS, (int) $scene->duration_seconds);
            $end = $cursor + $duration;

            $scene->forceFill([
                'sequence' => $sequence,
                'start_second' => $cursor,
                'end_second' => $end,
                'duration_seconds' => $duration,
            ])->save();

            $cursor = $end;
            $sequence++;
        }

        $reel->forceFill([
            'total_duration_seconds' => $cursor,
        ])->save();

        return $reel->refresh();
    }
}
