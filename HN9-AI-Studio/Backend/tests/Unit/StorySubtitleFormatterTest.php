<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Story\Support\Subtitles\StorySubtitleFormatter;
use PHPUnit\Framework\TestCase;

final class StorySubtitleFormatterTest extends TestCase
{
    public function test_srt_and_vtt_use_utf8_without_bom_and_escape_text(): void
    {
        $formatter = new StorySubtitleFormatter;
        $cues = [[
            'start_ms' => 1500,
            'end_ms' => 3200,
            'text' => "Hello <world> &\nA --> B",
        ]];

        $srt = $formatter->srt($cues);
        $vtt = $formatter->vtt($cues);

        $this->assertSame("1\n00:00:01,500 --> 00:00:03,200\nHello <world> &\nA — B\n", $srt);
        $this->assertSame("WEBVTT\n\n00:00:01.500 --> 00:00:03.200\nHello &lt;world&gt; &amp;\nA — B\n", $vtt);
        $this->assertStringStartsNotWith("\xEF\xBB\xBF", $srt);
        $this->assertStringStartsNotWith("\xEF\xBB\xBF", $vtt);
        $this->assertStringNotContainsString("\r", $srt.$vtt);
    }
}
