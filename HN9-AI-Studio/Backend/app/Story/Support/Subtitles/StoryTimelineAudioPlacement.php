<?php

declare(strict_types=1);

namespace App\Story\Support\Subtitles;

/**
 * Reproduces StoryRenderService::segments() overlay starts from a stored snapshot.
 * Audio starts at the start of the picture clip it follows. Picture clips overlap
 * by the dissolve or fade length. This class does not call segments().
 */
final class StoryTimelineAudioPlacement
{
    /**
     * @param  list<array<string, mixed>>  $clips
     * @param  list<array<string, mixed>>  $transitions
     * @return array{duration_ms: int, audio: list<array<string, mixed>>}
     */
    public function place(array $clips, array $transitions): array
    {
        $incoming = [];
        foreach ($transitions as $transition) {
            $to = (string) ($transition['to_clip_id'] ?? '');
            if ($to !== '') {
                $incoming[$to] = $transition;
            }
        }
        usort($clips, static fn (array $left, array $right): int => ((int) ($left['position'] ?? 0)) <=> ((int) ($right['position'] ?? 0)));

        $audio = [];
        $elapsed = 0.0;
        $currentStart = 0.0;
        $pictures = 0;
        foreach ($clips as $clip) {
            $kind = (string) ($clip['media_kind'] ?? '');
            $in = max(0, (int) ($clip['in_ms'] ?? 0)) / 1000;
            $out = max(0, (int) ($clip['out_ms'] ?? 0)) / 1000;
            if ($kind === 'audio') {
                $audio[] = [
                    'id' => (string) ($clip['id'] ?? ''),
                    'disk' => (string) ($clip['disk'] ?? ''),
                    'path' => (string) ($clip['path'] ?? ''),
                    'overlay_start_ms' => (int) round($currentStart * 1000),
                    'in_ms' => (int) ($clip['in_ms'] ?? 0),
                    'out_ms' => (int) ($clip['out_ms'] ?? 0),
                ];

                continue;
            }
            if ($kind !== 'video') {
                continue;
            }
            $transition = $pictures === 0 ? null : ($incoming[(string) ($clip['id'] ?? '')] ?? null);
            $type = is_array($transition) && in_array($transition['type'] ?? null, ['dissolve', 'fade'], true) ? (string) $transition['type'] : 'cut';
            $fade = $type === 'cut' ? 0.0 : max(0, (int) ($transition['duration_ms'] ?? 0)) / 1000;
            $currentStart = max(0.0, $elapsed - $fade);
            $elapsed = $currentStart + max(0.0, ($out > $in ? $out : 0) - ($out > $in ? $in : 0));
            if ($out <= $in) {
                $elapsed = $currentStart;
            }
            $pictures++;
        }

        return [
            'duration_ms' => (int) round($elapsed * 1000),
            'audio' => $audio,
        ];
    }
}
