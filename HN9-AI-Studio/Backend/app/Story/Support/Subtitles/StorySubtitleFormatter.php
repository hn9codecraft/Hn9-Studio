<?php

declare(strict_types=1);

namespace App\Story\Support\Subtitles;

/**
 * SRT and WebVTT writers. UTF-8, no BOM, LF only.
 */
final class StorySubtitleFormatter
{
    /**
     * @param  list<array{start_ms: int, end_ms: int, text: string}>  $cues
     */
    public function srt(array $cues): string
    {
        $blocks = [];
        foreach (array_values($cues) as $index => $cue) {
            $blocks[] = ($index + 1)."\n".$this->stamp($cue['start_ms'], ',').' --> '.$this->stamp($cue['end_ms'], ',')."\n".$this->text($cue['text']);
        }

        return $blocks === [] ? '' : implode("\n\n", $blocks)."\n";
    }

    /**
     * @param  list<array{start_ms: int, end_ms: int, text: string}>  $cues
     */
    public function vtt(array $cues): string
    {
        $blocks = ['WEBVTT', ''];
        foreach ($cues as $cue) {
            $blocks[] = $this->stamp($cue['start_ms'], '.').' --> '.$this->stamp($cue['end_ms'], '.')."\n".$this->text($cue['text'], true);
            $blocks[] = '';
        }

        return implode("\n", $blocks);
    }

    private function stamp(int $ms, string $decimal): string
    {
        $ms = max(0, $ms);
        $hours = intdiv($ms, 3_600_000);
        $ms %= 3_600_000;
        $minutes = intdiv($ms, 60_000);
        $ms %= 60_000;
        $seconds = intdiv($ms, 1000);
        $millis = $ms % 1000;

        return sprintf('%02d:%02d:%02d%s%03d', $hours, $minutes, $seconds, $decimal, $millis);
    }

    private function text(string $text, bool $escape = false): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace('-->', '—', $text));
        $clean = is_string($clean) ? $clean : $text;
        if ($escape) {
            $clean = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $clean);
        }

        return $clean;
    }
}
