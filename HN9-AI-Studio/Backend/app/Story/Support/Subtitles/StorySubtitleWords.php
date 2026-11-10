<?php

declare(strict_types=1);

namespace App\Story\Support\Subtitles;

/**
 * Turns alignment characters, or spoken text, into timed words.
 * Combining marks stay with their base character. Graphemes are never split.
 */
final class StorySubtitleWords
{
    /**
     * @param  array<string, mixed>  $alignment
     * @return list<array{text: string, start_ms: int, end_ms: int}>
     */
    public function fromAlignment(array $alignment): array
    {
        $characters = is_array($alignment['characters'] ?? null) ? array_values($alignment['characters']) : [];
        $starts = is_array($alignment['character_start_times_seconds'] ?? null) ? $alignment['character_start_times_seconds'] : [];
        $ends = is_array($alignment['character_end_times_seconds'] ?? null) ? $alignment['character_end_times_seconds'] : [];
        $words = [];
        $text = '';
        $start = null;
        $end = null;

        $count = count($characters);
        for ($index = 0; $index < $count; $index++) {
            $character = (string) $characters[$index];
            if (preg_match('/^\s+$/u', $character) === 1) {
                if ($text !== '' && $start !== null && $end !== null) {
                    $words[] = ['text' => $text, 'start_ms' => $start, 'end_ms' => max($start, $end)];
                }
                $text = '';
                $start = null;
                $end = null;

                continue;
            }
            $start ??= $this->ms($starts[$index] ?? 0);
            $end = $this->ms($ends[$index] ?? ($starts[$index] ?? 0));
            $text .= $character;
        }
        if ($text !== '' && $start !== null && $end !== null) {
            $words[] = ['text' => $text, 'start_ms' => $start, 'end_ms' => max($start, $end)];
        }

        return $words;
    }

    /**
     * @return list<array{text: string, start_ms: int, end_ms: int}>
     */
    public function approximate(string $spoken, int $durationMs): array
    {
        $spoken = trim($spoken);
        if ($spoken === '' || $durationMs <= 0) {
            return [];
        }
        $parts = preg_split('/\s+/u', $spoken) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $word): bool => $word !== ''));
        if ($parts === []) {
            return [];
        }
        $weights = array_map(fn (string $word): int => max(1, count($this->graphemes($word))), $parts);
        $total = array_sum($weights);
        $lead = 150;
        $usable = max(1, $durationMs - $lead);
        $cursor = $lead;
        $words = [];
        $used = 0;
        foreach ($parts as $index => $word) {
            $used += $weights[$index];
            $next = $index === count($parts) - 1 ? $durationMs : $lead + (int) round($usable * ($used / $total));
            $words[] = ['text' => $word, 'start_ms' => $cursor, 'end_ms' => max($cursor + 1, $next)];
            $cursor = max($cursor + 1, $next);
        }

        return $words;
    }

    /**
     * @return list<string>
     */
    public function graphemes(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (function_exists('grapheme_extract')) {
            $graphemes = [];
            $offset = 0;
            $length = strlen($text);
            while ($offset < $length) {
                $grapheme = grapheme_extract($text, 1, \GRAPHEME_EXTR_COUNT, $offset, $next);
                if (! is_string($grapheme) || $grapheme === '') {
                    break;
                }
                $graphemes[] = $grapheme;
                $offset = (int) $next;
            }

            return $graphemes;
        }

        $split = preg_split('/\X/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($split) ? $split : [$text];
    }

    private function ms(mixed $seconds): int
    {
        return (int) round((float) $seconds * 1000);
    }
}
