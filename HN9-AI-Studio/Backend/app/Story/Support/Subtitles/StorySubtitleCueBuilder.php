<?php

declare(strict_types=1);

namespace App\Story\Support\Subtitles;

/**
 * Groups timed words into cues that stay inside the line and duration limits.
 */
final class StorySubtitleCueBuilder
{
    public function __construct(private StorySubtitleWords $words = new StorySubtitleWords) {}

    /**
     * @param  list<array{text: string, start_ms: int, end_ms: int}>  $words
     * @return list<array{start_ms: int, end_ms: int, text: string}>
     */
    public function build(array $words, int $maxChars, int $maxLines, int $minCueMs, int $maxCueMs, int $gapMs, ?int $endMs = null): array
    {
        $cues = [];
        $line = '';
        $lines = [];
        $start = null;
        $end = null;

        $flush = function () use (&$cues, &$lines, &$line, &$start, &$end, $minCueMs, $gapMs): void {
            if ($line !== '') {
                $lines[] = $line;
                $line = '';
            }
            if ($lines === [] || $start === null || $end === null) {
                $lines = [];
                $start = null;
                $end = null;

                return;
            }
            $cueEnd = max($end, $start + $minCueMs);
            if ($cues !== []) {
                $previous = count($cues) - 1;
                $limit = $start - $gapMs;
                if ($cues[$previous]['end_ms'] > $limit) {
                    $cues[$previous]['end_ms'] = max($cues[$previous]['start_ms'] + 1, $limit);
                }
            }
            $cues[] = [
                'start_ms' => $start,
                'end_ms' => $cueEnd,
                'text' => implode("\n", $lines),
            ];
            $lines = [];
            $start = null;
            $end = null;
        };

        foreach ($words as $word) {
            $text = trim((string) ($word['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $wordStart = (int) $word['start_ms'];
            $wordEnd = max($wordStart + 1, (int) $word['end_ms']);
            if ($endMs !== null && $wordStart >= $endMs) {
                break;
            }
            if ($endMs !== null) {
                $wordEnd = min($wordEnd, $endMs);
            }
            $candidate = $line === '' ? $text : $line.' '.$text;
            $tooLong = count($this->words->graphemes($candidate)) > $maxChars;
            $tooLate = $start !== null && ($wordEnd - $start) > $maxCueMs;
            if ($tooLate) {
                $flush();
            }
            if ($tooLong) {
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                if (count($lines) >= $maxLines) {
                    $flush();
                }
            }
            if ($start === null) {
                $start = $wordStart;
            }
            $line = $line === '' ? $text : $line.' '.$text;
            $end = $wordEnd;
            if ($this->sentenceEnd($text) || count($lines) + 1 >= $maxLines && $tooLong) {
                $flush();
            }
        }
        $flush();

        $previousEnd = 0;
        foreach ($cues as $index => $cue) {
            $startAt = max($cue['start_ms'], $previousEnd);
            $endAt = max($startAt + 1, $cue['end_ms']);
            if ($endMs !== null) {
                $endAt = min($endAt, $endMs);
            }
            if ($endAt <= $startAt) {
                unset($cues[$index]);

                continue;
            }
            $cues[$index]['start_ms'] = $startAt;
            $cues[$index]['end_ms'] = $endAt;
            $previousEnd = $endAt + $gapMs;
        }

        return array_values($cues);
    }

    private function sentenceEnd(string $word): bool
    {
        return preg_match('/[.?!।॥]["\']?$/u', $word) === 1;
    }
}
