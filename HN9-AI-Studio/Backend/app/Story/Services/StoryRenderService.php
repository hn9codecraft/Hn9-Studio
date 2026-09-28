<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryTimeline;
use App\Story\Models\StoryTimelineClip;
use App\Story\Models\StoryTimelineTransition;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Renders a timeline into one private file from stored clips. No provider calls.
 */
final class StoryRenderService
{
    public const SOURCE_MISSING = 'story_render_source_missing';

    public const EMPTY = 'story_render_empty';

    public function __construct(private StoryTimelineComposer $composer) {}

    /**
     * @return array<string, mixed>
     */
    public function start(Project $project, string $reelUuid): array
    {
        $reel = $this->reel($project, $reelUuid);
        $timeline = StoryTimeline::query()->where('story_reel_id', $reel->id)->first();
        if (! $timeline instanceof StoryTimeline) {
            throw new StoryException('The timeline has no clips to render.', self::EMPTY, 422);
        }

        $clips = StoryTimelineClip::query()
            ->where('story_timeline_id', $timeline->id)
            ->orderBy('position')
            ->get()
            ->all();
        $snapshot = $this->snapshot($timeline, $clips);
        $render = StoryFinalRender::query()->create([
            'story_timeline_id' => $timeline->id,
            'story_reel_id' => $reel->id,
            'status' => StoryVideoJobStatus::Queued->value,
            'timeline_version' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'timeline_snapshot' => $snapshot,
        ]);

        $render->forceFill(['status' => StoryVideoJobStatus::Processing->value])->save();

        try {
            $segments = $this->segments($clips);
            $bytes = $this->composer->compose($segments);
            $partial = 'renders/'.$render->uuid.'.partial';
            $final = 'renders/'.$render->uuid.'.mp4';
            Storage::disk('videos')->put($partial, $bytes);
            $stored = Storage::disk('videos')->get($partial);
            if (! is_string($stored) || $stored !== $bytes) {
                throw new StoryException('The render file could not be stored.', 'story_render_failed', 422);
            }
            Storage::disk('videos')->move($partial, $final);
            if (Storage::disk('videos')->exists($partial)) {
                Storage::disk('videos')->delete($partial);
            }
            $render->forceFill([
                'status' => StoryVideoJobStatus::Completed->value,
                'disk' => 'videos',
                'path' => $final,
                'mime' => 'video/mp4',
                'size_bytes' => strlen($bytes),
                'checksum' => hash('sha256', $bytes),
                'error_code' => null,
                'error_message' => null,
            ])->save();
        } catch (Throwable $exception) {
            $this->discardPartial($render);
            $code = $exception instanceof StoryException ? $exception->errorCode() : 'story_render_failed';
            $render->forceFill([
                'status' => StoryVideoJobStatus::Failed->value,
                'disk' => null,
                'path' => null,
                'mime' => null,
                'size_bytes' => null,
                'checksum' => null,
                'error_code' => $code,
                'error_message' => $exception->getMessage(),
            ])->save();
            if ($exception instanceof StoryException) {
                throw $exception;
            }

            throw new StoryException('The timeline could not be rendered.', 'story_render_failed', 422);
        }

        return $this->payload($render->fresh() ?? $render);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Project $project, string $reelUuid, string $renderUuid): array
    {
        return $this->payload($this->find($project, $reelUuid, $renderUuid));
    }

    public function file(Project $project, string $reelUuid, string $renderUuid): StreamedResponse
    {
        $render = $this->find($project, $reelUuid, $renderUuid);
        if ($render->status !== StoryVideoJobStatus::Completed->value || $render->disk === null || $render->path === null) {
            throw new StoryException('The final render file is not ready.', 'story_render_not_ready', 422);
        }
        if (! Storage::disk($render->disk)->exists($render->path)) {
            throw new StoryException('The final render file is missing.', self::SOURCE_MISSING, 422);
        }

        return Storage::disk($render->disk)->response($render->path, basename($render->path), [
            'Content-Type' => $render->mime ?? 'video/mp4',
        ]);
    }

    /**
     * @param  list<StoryTimelineClip>  $clips
     * @return list<array{media_kind: string, in_ms: int, out_ms: int, transition: string|null, transition_ms: int, bytes: string}>
     */
    private function segments(array $clips): array
    {
        if ($clips === []) {
            throw new StoryException('The timeline has no clips to render.', self::EMPTY, 422);
        }

        $transitions = [];
        $timelineId = $clips[0]->story_timeline_id;
        foreach (StoryTimelineTransition::query()->where('story_timeline_id', $timelineId)->get() as $transition) {
            $transitions[$transition->from_clip_id] = $transition;
        }

        $segments = [];
        foreach ($clips as $index => $clip) {
            if (! in_array($clip->disk, ['videos', 'voice'], true) || str_contains($clip->path, '..')) {
                throw new StoryException('A timeline source file is missing.', self::SOURCE_MISSING, 422);
            }
            if (! Storage::disk($clip->disk)->exists($clip->path)) {
                throw new StoryException('A timeline source file is missing.', self::SOURCE_MISSING, 422);
            }
            $bytes = Storage::disk($clip->disk)->get($clip->path);
            if (! is_string($bytes) || $bytes === '') {
                throw new StoryException('A timeline source file is missing.', self::SOURCE_MISSING, 422);
            }
            $incoming = $index > 0 ? ($transitions[$clips[$index - 1]->id] ?? null) : null;
            $segments[] = [
                'media_kind' => $clip->media_kind,
                'in_ms' => $clip->in_ms,
                'out_ms' => $clip->out_ms,
                'transition' => $incoming?->type,
                'transition_ms' => (int) ($incoming->duration_ms ?? 0),
                'bytes' => $bytes,
            ];
        }

        return $segments;
    }

    private function discardPartial(StoryFinalRender $render): void
    {
        $disk = Storage::disk('videos');
        foreach (['renders/'.$render->uuid.'.partial', 'renders/'.$render->uuid.'.mp4'] as $path) {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }
    }

    /**
     * @param  list<StoryTimelineClip>  $clips
     * @return array<string, mixed>
     */
    private function snapshot(StoryTimeline $timeline, array $clips): array
    {
        $ids = [];
        foreach ($clips as $clip) {
            $ids[$clip->id] = $clip->uuid;
        }

        return [
            'timeline_id' => $timeline->uuid,
            'clips' => array_map(static function (StoryTimelineClip $clip): array {
                return [
                    'id' => $clip->uuid,
                    'position' => $clip->position,
                    'media_kind' => $clip->media_kind,
                    'disk' => $clip->disk,
                    'path' => $clip->path,
                    'in_ms' => $clip->in_ms,
                    'out_ms' => $clip->out_ms,
                ];
            }, $clips),
            'transitions' => StoryTimelineTransition::query()
                ->where('story_timeline_id', $timeline->id)
                ->get()
                ->map(static function (StoryTimelineTransition $transition) use ($ids): array {
                    return [
                        'from_clip_id' => $ids[$transition->from_clip_id] ?? null,
                        'to_clip_id' => $ids[$transition->to_clip_id] ?? null,
                        'type' => $transition->type,
                        'duration_ms' => $transition->duration_ms,
                    ];
                })
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(StoryFinalRender $render): array
    {
        $ready = $render->status === StoryVideoJobStatus::Completed->value
            && is_string($render->path)
            && $render->path !== ''
            && is_string($render->disk)
            && Storage::disk($render->disk)->exists($render->path);

        return [
            'id' => $render->uuid,
            'timeline_id' => $render->timeline?->uuid ?? ($render->timeline_snapshot['timeline_id'] ?? null),
            'timeline_version' => $render->timeline_version,
            'status' => $render->status,
            'disk' => $ready ? $render->disk : null,
            'path' => $ready ? $render->path : null,
            'mime' => $ready ? $render->mime : null,
            'size_bytes' => $ready ? $render->size_bytes : null,
            'error_code' => $render->error_code,
            'has_file' => $ready,
            'output_url' => null,
        ];
    }

    private function find(Project $project, string $reelUuid, string $renderUuid): StoryFinalRender
    {
        $reel = $this->reel($project, $reelUuid);
        $render = StoryFinalRender::query()
            ->where('uuid', $renderUuid)
            ->where('story_reel_id', $reel->id)
            ->first();
        if (! $render instanceof StoryFinalRender) {
            throw StoryException::notFound('Render');
        }

        return $render;
    }

    private function reel(Project $project, string $reelUuid): StoryReel
    {
        $reel = StoryReel::query()
            ->where('uuid', $reelUuid)
            ->whereHas('workspace', static function ($query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->first();
        if (! $reel instanceof StoryReel) {
            throw StoryException::notFound('Reel');
        }

        return $reel;
    }
}
