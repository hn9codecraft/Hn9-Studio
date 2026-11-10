<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Models\User;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Models\StoryAudioTiming;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StoryReel;
use App\Story\Models\StorySceneAudio;
use App\Story\Models\StorySubtitleTrack;
use App\Story\Models\StoryWorkspace;
use App\Story\Support\Subtitles\StorySubtitleCueBuilder;
use App\Story\Support\Subtitles\StorySubtitleFormatter;
use App\Story\Support\Subtitles\StorySubtitleWords;
use App\Story\Support\Subtitles\StoryTimelineAudioPlacement;
use Illuminate\Support\Facades\Storage;

/**
 * Builds SRT and WebVTT from voiceovers already stored for a finished render.
 * No provider call. Existing voiceovers without a timestamp sidecar are approximate.
 */
final class StorySubtitleService
{
    public function __construct(
        private StorySubtitleWords $words,
        private StorySubtitleCueBuilder $cues,
        private StorySubtitleFormatter $formatter,
        private StoryTimelineAudioPlacement $placement,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function index(Project $project, string $reelUuid, string $renderUuid): array
    {
        $render = $this->render($project, $reelUuid, $renderUuid);

        return StorySubtitleTrack::query()
            ->where('source_render_id', $render->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (StorySubtitleTrack $track): array => $this->summary($track))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Project $project, string $reelUuid, string $renderUuid, User $actor): array
    {
        $render = $this->render($project, $reelUuid, $renderUuid);
        if ($render->status !== StoryVideoJobStatus::Completed->value) {
            throw new StoryException('Subtitles can be made after the video is built.', 'story_subtitle_not_ready', 422);
        }
        $reel = $render->reel;
        $workspace = $reel?->workspace;
        if (! $reel instanceof StoryReel || ! $workspace instanceof StoryWorkspace) {
            throw StoryException::notFound('Reel');
        }

        $built = $this->compose($render, $workspace);
        $hash = hash('sha256', json_encode($built['cues'], JSON_THROW_ON_ERROR));
        $existing = StorySubtitleTrack::query()
            ->where('source_render_id', $render->id)
            ->where('cues_hash', $hash)
            ->first();
        if ($existing instanceof StorySubtitleTrack) {
            return $this->detail($existing);
        }

        $disk = (string) config('story_subtitles.disk', 'exports');
        $track = new StorySubtitleTrack;
        $track->forceFill([
            'story_workspace_id' => $workspace->id,
            'story_reel_id' => $reel->id,
            'source_render_id' => $render->id,
            'language' => $built['language'],
            'timing_mode' => $built['timing_mode'],
            'cue_count' => count($built['cues']),
            'cues_hash' => $hash,
            'cues' => $built['cues'],
            'disk' => $disk,
            'srt_path' => 'pending',
            'vtt_path' => 'pending',
            'created_by' => $actor->id,
        ])->save();

        $base = 'subtitles/'.$workspace->uuid.'/'.$track->uuid;
        $srt = $base.'.srt';
        $vtt = $base.'.vtt';
        $this->assertSafe($srt);
        $this->assertSafe($vtt);
        Storage::disk($disk)->put($srt, $this->formatter->srt($built['cues']));
        Storage::disk($disk)->put($vtt, $this->formatter->vtt($built['cues']));
        $track->forceFill(['srt_path' => $srt, 'vtt_path' => $vtt])->save();

        return $this->detail($track);
    }

    public function file(Project $project, string $reelUuid, string $renderUuid, string $trackUuid, string $format): array
    {
        $track = $this->track($project, $reelUuid, $renderUuid, $trackUuid);
        $path = $format === 'vtt' ? $track->vtt_path : $track->srt_path;
        $this->assertSafe($path);
        if (! Storage::disk($track->disk)->exists($path)) {
            throw StoryException::notFound('Subtitle file');
        }
        $render = $track->sourceRender;
        $slug = (string) ($render?->reel?->workspace?->project?->slug ?: 'story');
        $short = substr((string) $render?->uuid, 0, 8);

        return [
            'body' => (string) Storage::disk($track->disk)->get($path),
            'content_type' => $format === 'vtt' ? 'text/vtt; charset=utf-8' : 'application/x-subrip; charset=utf-8',
            'filename' => $slug.'-'.$short.'.'.$format,
        ];
    }

    /**
     * @return array{cues: list<array{start_ms: int, end_ms: int, text: string}>, timing_mode: string, language: string}
     */
    private function compose(StoryFinalRender $render, StoryWorkspace $workspace): array
    {
        $snapshot = is_array($render->timeline_snapshot) ? $render->timeline_snapshot : [];
        $placed = $this->placement->place(
            is_array($snapshot['clips'] ?? null) ? $snapshot['clips'] : [],
            is_array($snapshot['transitions'] ?? null) ? $snapshot['transitions'] : [],
        );
        $aspect = (string) ($workspace->bible?->aspect_ratio ?: '16:9');
        $limits = (array) config('story_subtitles.max_chars_per_line', []);
        $maxChars = (int) ($limits[$aspect] ?? $limits['16:9'] ?? 42);
        $modes = [];
        $cues = [];
        foreach ($placed['audio'] as $clip) {
            if (! $this->safe((string) $clip['disk'], (string) $clip['path'])) {
                continue;
            }
            $audio = StorySceneAudio::query()
                ->where('disk', $clip['disk'])
                ->where('path', $clip['path'])
                ->first();
            if (! $audio instanceof StorySceneAudio) {
                continue;
            }
            $words = $this->wordsFor($audio);
            $modes[] = $words['mode'];
            $shifted = $this->shift($words['words'], $clip, $placed['duration_ms']);
            $cues = array_merge($cues, $this->cues->build(
                $shifted,
                $maxChars,
                (int) config('story_subtitles.max_lines', 2),
                (int) config('story_subtitles.min_cue_ms', 800),
                (int) config('story_subtitles.max_cue_ms', 6000),
                (int) config('story_subtitles.gap_ms', 80),
                $placed['duration_ms'],
            ));
        }
        usort($cues, static fn (array $left, array $right): int => $left['start_ms'] <=> $right['start_ms']);
        $language = trim((string) ($workspace->bible?->language ?? ''));

        return [
            'cues' => $cues,
            'timing_mode' => $this->mode($modes),
            'language' => $language === '' ? 'und' : $language,
        ];
    }

    /**
     * @return array{mode: string, words: list<array{text: string, start_ms: int, end_ms: int}>}
     */
    private function wordsFor(StorySceneAudio $audio): array
    {
        $audio->loadMissing('job');
        $operation = $audio->job?->operation_id;
        $path = is_string($operation) && $operation !== '' ? 'alignments/'.$operation.'.json' : null;
        if ($path !== null && $this->safe('voice', $path) && Storage::disk('voice')->exists($path)) {
            $json = json_decode((string) Storage::disk('voice')->get($path), true);

            return [
                'mode' => 'exact',
                'words' => $this->words->fromAlignment(is_array($json) ? $json : []),
            ];
        }
        $timing = StoryAudioTiming::query()->where('story_scene_audio_id', $audio->id)->first();
        $duration = $timing instanceof StoryAudioTiming ? (int) $timing->duration_ms : 0;

        return [
            'mode' => 'approximate',
            'words' => $this->words->approximate((string) $audio->prompt, $duration),
        ];
    }

    /**
     * @param  list<array{text: string, start_ms: int, end_ms: int}>  $words
     * @param  array<string, mixed>  $clip
     * @return list<array{text: string, start_ms: int, end_ms: int}>
     */
    private function shift(array $words, array $clip, int $renderEnd): array
    {
        $in = max(0, (int) $clip['in_ms']);
        $out = max(0, (int) $clip['out_ms']);
        $open = $out > $in;
        $overlay = (int) $clip['overlay_start_ms'];
        $shifted = [];
        foreach ($words as $word) {
            $start = (int) $word['start_ms'];
            $end = (int) $word['end_ms'];
            if ($open && ($end <= $in || $start >= $out)) {
                continue;
            }
            if ($open) {
                $start = max($start, $in);
                $end = min($end, $out);
            }
            $start = $overlay + ($start - $in);
            $end = $overlay + ($end - $in);
            if ($start >= $renderEnd) {
                continue;
            }
            $shifted[] = ['text' => $word['text'], 'start_ms' => $start, 'end_ms' => min($end, $renderEnd)];
        }

        return $shifted;
    }

    /**
     * @param  list<string>  $modes
     */
    private function mode(array $modes): string
    {
        $modes = array_values(array_unique($modes));
        if ($modes === [] || $modes === ['approximate']) {
            return 'approximate';
        }
        if ($modes === ['exact']) {
            return 'exact';
        }

        return 'mixed';
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(StorySubtitleTrack $track): array
    {
        $track->loadMissing('burnedRender');

        return [
            'uuid' => $track->uuid,
            'language' => $track->language,
            'timing_mode' => $track->timing_mode,
            'cue_count' => (int) $track->cue_count,
            'created_at' => $track->created_at?->toIso8601String(),
            'burned_render_uuid' => $track->burnedRender?->uuid,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(StorySubtitleTrack $track): array
    {
        $cues = is_array($track->cues) ? array_slice($track->cues, 0, 200) : [];

        return [...$this->summary($track), 'cues' => $cues];
    }

    private function render(Project $project, string $reelUuid, string $renderUuid): StoryFinalRender
    {
        $render = StoryFinalRender::query()
            ->where('uuid', $renderUuid)
            ->whereHas('reel', function ($reel) use ($project, $reelUuid): void {
                $reel->where('uuid', $reelUuid)->whereHas('workspace', static fn ($workspace) => $workspace->where('project_id', $project->id));
            })
            ->first();
        if (! $render instanceof StoryFinalRender) {
            throw StoryException::notFound('Render');
        }
        $render->loadMissing('reel.workspace.bible', 'reel.workspace.project');

        return $render;
    }

    private function track(Project $project, string $reelUuid, string $renderUuid, string $trackUuid): StorySubtitleTrack
    {
        $render = $this->render($project, $reelUuid, $renderUuid);
        $track = StorySubtitleTrack::query()
            ->where('uuid', $trackUuid)
            ->where('source_render_id', $render->id)
            ->first();
        if (! $track instanceof StorySubtitleTrack) {
            throw StoryException::notFound('Subtitle track');
        }
        $track->loadMissing('sourceRender.reel.workspace.project');

        return $track;
    }

    private function assertSafe(string $path): void
    {
        if (! $this->safe('exports', $path)) {
            throw new StoryException('The subtitle file could not be stored.', 'story_subtitle_invalid', 422);
        }
    }

    private function safe(string $disk, string $path): bool
    {
        return $disk !== '' && $path !== '' && ! str_contains($path, '..') && ! str_contains($path, '://');
    }
}
