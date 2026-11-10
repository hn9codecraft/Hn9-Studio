<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Story\Support\Subtitles\StoryTimelineAudioPlacement;
use PHPUnit\Framework\TestCase;

final class StoryTimelineAudioPlacementTest extends TestCase
{
    public function test_overlay_starts_match_cut_dissolve_and_fade(): void
    {
        $placement = new StoryTimelineAudioPlacement;
        $cut = $placement->place([
            ['id' => 'v1', 'position' => 1, 'media_kind' => 'video', 'in_ms' => 0, 'out_ms' => 10000],
            ['id' => 'a1', 'position' => 2, 'media_kind' => 'audio', 'disk' => 'voice', 'path' => 'a.mp3', 'in_ms' => 0, 'out_ms' => 10000],
            ['id' => 'v2', 'position' => 3, 'media_kind' => 'video', 'in_ms' => 0, 'out_ms' => 8000],
            ['id' => 'a2', 'position' => 4, 'media_kind' => 'audio', 'disk' => 'voice', 'path' => 'b.mp3', 'in_ms' => 500, 'out_ms' => 7000],
        ], []);

        $this->assertSame(18000, $cut['duration_ms']);
        $this->assertSame(0, $cut['audio'][0]['overlay_start_ms']);
        $this->assertSame(10000, $cut['audio'][1]['overlay_start_ms']);

        $dissolve = $placement->place([
            ['id' => 'v1', 'position' => 1, 'media_kind' => 'video', 'in_ms' => 0, 'out_ms' => 10000],
            ['id' => 'a1', 'position' => 2, 'media_kind' => 'audio', 'in_ms' => 0, 'out_ms' => 10000],
            ['id' => 'v2', 'position' => 3, 'media_kind' => 'video', 'in_ms' => 0, 'out_ms' => 8000],
            ['id' => 'a2', 'position' => 4, 'media_kind' => 'audio', 'in_ms' => 0, 'out_ms' => 8000],
        ], [['from_clip_id' => 'v1', 'to_clip_id' => 'v2', 'type' => 'dissolve', 'duration_ms' => 1000]]);

        $this->assertSame(0, $dissolve['audio'][0]['overlay_start_ms']);
        $this->assertSame(9000, $dissolve['audio'][1]['overlay_start_ms']);
        $this->assertSame(17000, $dissolve['duration_ms']);

        $fade = $placement->place([
            ['id' => 'v1', 'position' => 1, 'media_kind' => 'video', 'in_ms' => 0, 'out_ms' => 5000],
            ['id' => 'v2', 'position' => 2, 'media_kind' => 'video', 'in_ms' => 0, 'out_ms' => 5000],
            ['id' => 'a2', 'position' => 3, 'media_kind' => 'audio', 'in_ms' => 0, 'out_ms' => 5000],
        ], [['from_clip_id' => 'v1', 'to_clip_id' => 'v2', 'type' => 'fade', 'duration_ms' => 500]]);

        $this->assertSame(4500, $fade['audio'][0]['overlay_start_ms']);
        $this->assertSame(9500, $fade['duration_ms']);
    }
}
