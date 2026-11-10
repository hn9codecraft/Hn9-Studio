<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Story\Support\Subtitles\StorySubtitleCueBuilder;
use App\Story\Support\Subtitles\StorySubtitleWords;
use PHPUnit\Framework\TestCase;

final class StorySubtitleCueBuilderTest extends TestCase
{
    public function test_gujarati_and_hindi_words_keep_matras_and_break_after_danda(): void
    {
        $words = new StorySubtitleWords;
        $gujarati = 'આ અમારી સફરની શરૂઆત છે। હવે આગળ વધીએ.';
        $alignment = $this->alignment($gujarati);
        $exact = $words->fromAlignment($alignment);

        $this->assertContains('સફરની', array_column($exact, 'text'));
        $this->assertContains('શરૂઆત', array_column($exact, 'text'));
        foreach ($exact as $word) {
            $this->assertSame($word['text'], implode('', $words->graphemes($word['text'])));
        }

        $cues = (new StorySubtitleCueBuilder)->build($exact, 42, 2, 800, 6000, 80);
        $joined = implode("\n", array_column($cues, 'text'));
        $this->assertStringContainsString('છે।', $joined);
        $this->assertLessThan(count($exact), count($cues));
        $this->assertCueLimits($cues, 42);

        $hindi = 'यह हमारी यात्रा की शुरुआत है। अब आगे बढ़ें.';
        $hindiWords = $words->fromAlignment($this->alignment($hindi));
        $this->assertContains('शुरुआत', array_column($hindiWords, 'text'));
        $hindiCues = (new StorySubtitleCueBuilder)->build($hindiWords, 30, 2, 800, 6000, 80);
        $this->assertStringContainsString('है।', implode("\n", array_column($hindiCues, 'text')));
        $this->assertCueLimits($hindiCues, 30);
    }

    /**
     * @param  list<array{start_ms: int, end_ms: int, text: string}>  $cues
     */
    private function assertCueLimits(array $cues, int $maxChars): void
    {
        $previous = 0;
        $words = new StorySubtitleWords;
        foreach ($cues as $cue) {
            $this->assertGreaterThanOrEqual($previous, $cue['start_ms']);
            $this->assertGreaterThan($cue['start_ms'], $cue['end_ms']);
            $this->assertLessThanOrEqual(6000, $cue['end_ms'] - $cue['start_ms']);
            $this->assertLessThanOrEqual(2, substr_count($cue['text'], "\n") + 1);
            foreach (explode("\n", $cue['text']) as $line) {
                $this->assertLessThanOrEqual($maxChars, count($words->graphemes($line)));
            }
            $previous = $cue['end_ms'];
        }
    }

    /**
     * @return array{characters: list<string>, character_start_times_seconds: list<float>, character_end_times_seconds: list<float>}
     */
    private function alignment(string $text): array
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $starts = [];
        $ends = [];
        foreach ($characters as $index => $character) {
            $starts[] = $index * 0.2;
            $ends[] = $index * 0.2 + 0.18;
        }

        return [
            'characters' => $characters,
            'character_start_times_seconds' => $starts,
            'character_end_times_seconds' => $ends,
        ];
    }
}
