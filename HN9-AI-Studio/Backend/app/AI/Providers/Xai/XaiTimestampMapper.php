<?php

declare(strict_types=1);

namespace App\AI\Providers\Xai;

/**
 * Maps the documented xAI TTS timestamp envelope onto the M16 alignment shape.
 *
 * Official fields: audio_timestamps.graph_chars and graph_times as [start, end] seconds.
 */
final class XaiTimestampMapper
{
    /**
     * @param  array<string, mixed>  $response
     * @return array{characters: list<string>, character_start_times_seconds: list<float>, character_end_times_seconds: list<float>}|null
     */
    public function alignment(array $response): ?array
    {
        $timestamps = $response['audio_timestamps'] ?? null;
        if (! is_array($timestamps)) {
            return null;
        }
        $chars = $timestamps['graph_chars'] ?? null;
        $times = $timestamps['graph_times'] ?? null;
        if (! is_array($chars) || ! is_array($times) || $chars === []) {
            return null;
        }

        $characters = [];
        $starts = [];
        $ends = [];
        foreach ($chars as $index => $char) {
            $pair = $times[$index] ?? null;
            if (! is_string($char) || ! is_array($pair) || ! isset($pair[0], $pair[1]) || ! is_numeric($pair[0]) || ! is_numeric($pair[1])) {
                continue;
            }
            $characters[] = $char;
            $starts[] = (float) $pair[0];
            $ends[] = (float) $pair[1];
        }

        if ($characters === []) {
            return null;
        }

        return [
            'characters' => $characters,
            'character_start_times_seconds' => $starts,
            'character_end_times_seconds' => $ends,
        ];
    }
}
