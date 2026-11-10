<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Jobs\ProcessStoryRenderJob;
use App\Models\Project;
use App\Models\User;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Media\StoryMediaOverlay;
use App\Story\Media\StoryMediaSegment;
use App\Story\Media\StoryMediaToolkit;
use App\Story\Exceptions\StoryException;
use App\Story\Models\StoryExport;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryTimeline;
use App\Story\Models\StoryTimelineClip;
use App\Story\Models\StoryTimelineTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Renders a timeline into one private MP4 from stored clips with FFmpeg.
 * No provider calls; when FFmpeg is missing the build fails honestly.
 */
final class StoryRenderService
{
    public const SOURCE_MISSING = 'story_render_source_missing';

    public const EMPTY = 'story_render_empty';

    public const IN_PROGRESS = 'story_render_in_progress';

    /** @var list<string> */
    public const ACTIVE = ['queued', 'processing', 'rendering'];

    public function __construct(private StoryMediaToolkit $media) {}

    /**
     * @return array<string, mixed>
     */
    public function start(Project $project, string $reelUuid, ?User $actor = null): array
    {
        $render = $this->open($project, $reelUuid, $actor);
        if ($this->queueEnabled()) {
            try {
                $this->enqueue($render);
            } catch (Throwable $exception) {
                Log::warning('story.render.queue_fallback', [
                    'render' => $render->uuid,
                    'error' => $exception::class,
                ]);
                $render->forceFill([
                    'status' => StoryVideoJobStatus::Processing->value,
                    'started_at' => now(),
                ])->save();
                $done = $this->renderNow($render->fresh() ?? $render);
                $payload = $this->payload($done);
                $payload['queued'] = false;
                $payload['fallback'] = 'sync';

                return $payload;
            }
            $payload = $this->payload($render->fresh() ?? $render);
            $payload['queued'] = true;

            return $payload;
        }

        $render->forceFill([
            'status' => StoryVideoJobStatus::Processing->value,
            'started_at' => now(),
        ])->save();

        return $this->payload($this->renderNow($render->fresh() ?? $render));
    }

    /**
     * Builds the file for a render row that is already processing or rendering.
     */
    public function renderNow(StoryFinalRender $render): StoryFinalRender
    {
        $render->loadMissing('reel.workspace.bible', 'timeline');
        $clips = StoryTimelineClip::query()
            ->with('sceneVersion')
            ->where('story_timeline_id', $render->story_timeline_id)
            ->orderBy('position')
            ->get()
            ->all();

        try {
            [$segments, $overlays] = $this->segments($clips);
            $reel = $render->reel;
            if (! $reel instanceof StoryReel) {
                throw new StoryException('The timeline could not be rendered.', 'story_render_failed', 422);
            }
            $file = $this->media->compose(
                $segments,
                $overlays,
                'renders/'.$render->uuid.'.mp4',
                $this->aspectRatio($reel),
            );
            $render->forceFill([
                'status' => StoryVideoJobStatus::Completed->value,
                'disk' => $file->disk,
                'path' => $file->path,
                'mime' => $file->mime,
                'size_bytes' => $file->size,
                'checksum' => $file->checksum,
                'error_code' => null,
                'error_message' => null,
            ])->save();
        } catch (Throwable $exception) {
            $this->markFailed($render, $exception);
            if ($exception instanceof StoryException) {
                throw $exception;
            }

            throw new StoryException('The timeline could not be rendered.', 'story_render_failed', 422);
        }

        return $render->fresh() ?? $render;
    }

    public function markFailed(StoryFinalRender $render, Throwable $exception): void
    {
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
            'error_message' => $this->safeError($exception),
        ])->save();
    }

    public function safeError(Throwable $exception): string
    {
        $message = $exception instanceof StoryException
            ? $exception->getMessage()
            : 'The timeline could not be rendered.';
        if ($message === '' || preg_match('/[\\\\\\/]|(ffmpeg)|(\\b[A-Za-z]:)/i', $message) === 1) {
            return 'The timeline could not be rendered.';
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Project $project, string $reelUuid, string $renderUuid): array
    {
        return $this->payload($this->find($project, $reelUuid, $renderUuid));
    }

    /**
     * A reel's final video builds, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function index(Project $project, string $reelUuid): array
    {
        $reel = $this->reel($project, $reelUuid);

        return StoryFinalRender::query()
            ->where('story_reel_id', $reel->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (StoryFinalRender $render): array => $this->payload($render))
            ->all();
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

    private function open(Project $project, string $reelUuid, ?User $actor): StoryFinalRender
    {
        $reel = $this->reel($project, $reelUuid);
        $timeline = StoryTimeline::query()->where('story_reel_id', $reel->id)->first();
        if (! $timeline instanceof StoryTimeline) {
            throw new StoryException('The timeline has no clips to render.', self::EMPTY, 422);
        }

        return DB::transaction(function () use ($reel, $timeline, $actor): StoryFinalRender {
            StoryReel::query()->whereKey($reel->id)->lockForUpdate()->first();
            $active = StoryFinalRender::query()
                ->where('story_reel_id', $reel->id)
                ->whereIn('status', self::ACTIVE)
                ->lockForUpdate()
                ->first();
            if ($active instanceof StoryFinalRender) {
                throw new StoryException(
                    'A render is already in progress for this video.',
                    self::IN_PROGRESS,
                    409,
                    ['render_id' => $active->uuid],
                );
            }

            $clips = StoryTimelineClip::query()
                ->with('sceneVersion')
                ->where('story_timeline_id', $timeline->id)
                ->orderBy('position')
                ->get()
                ->all();
            $snapshot = $this->snapshot($timeline, $clips);

            return StoryFinalRender::query()->create([
                'story_timeline_id' => $timeline->id,
                'story_reel_id' => $reel->id,
                'requested_by' => $actor?->id,
                'status' => StoryVideoJobStatus::Queued->value,
                'timeline_version' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'timeline_snapshot' => $snapshot,
                'queued_at' => now(),
            ]);
        });
    }

    private function enqueue(StoryFinalRender $render): void
    {
        $connection = config('story_render.queue.connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;
        // Resolving the connection here surfaces a misconfigured queue before the
        // response is sent. afterCommit still delays the push until the row commits.
        app('queue')->connection($connection);

        $pending = ProcessStoryRenderJob::dispatch($render->id, $render->uuid);
        if ($connection !== null) {
            $pending->onConnection($connection);
        }
        $pending->onQueue((string) config('story_render.queue.name', 'default'))->afterCommit();
    }

    private function queueEnabled(): bool
    {
        return (bool) config('story_render.queue.enabled', false);
    }

    /**
     * Picture clips play in order with their transitions; each sound clip is
     * laid under the picture clip it follows.
     *
     * @param  list<StoryTimelineClip>  $clips
     * @return array{0: list<StoryMediaSegment>, 1: list<StoryMediaOverlay>}
     */
    private function segments(array $clips): array
    {
        if ($clips === []) {
            throw new StoryException('The timeline has no clips to render.', self::EMPTY, 422);
        }

        $incoming = [];
        $timelineId = $clips[0]->story_timeline_id;
        foreach (StoryTimelineTransition::query()->where('story_timeline_id', $timelineId)->get() as $transition) {
            $incoming[$transition->to_clip_id] = $transition;
        }

        $segments = [];
        $overlays = [];
        $elapsed = 0.0;
        $currentStart = 0.0;
        foreach ($clips as $clip) {
            if (! in_array($clip->disk, ['videos', 'voice'], true) || str_contains((string) $clip->path, '..')) {
                throw new StoryException('A timeline source file is missing.', self::SOURCE_MISSING, 422);
            }
            if (! Storage::disk($clip->disk)->exists($clip->path)) {
                throw new StoryException('A timeline source file is missing.', self::SOURCE_MISSING, 422);
            }

            $in = max(0, (int) $clip->in_ms) / 1000;
            $out = max(0, (int) $clip->out_ms) / 1000;
            if ($clip->media_kind === 'audio') {
                $overlays[] = new StoryMediaOverlay($clip->disk, $clip->path, $currentStart, $in, $out > $in ? $out : null);

                continue;
            }

            $transition = $segments === [] ? null : ($incoming[$clip->id] ?? null);
            $type = in_array($transition?->type, ['dissolve', 'fade'], true) ? $transition->type : 'cut';
            $fade = $type === 'cut' ? 0.0 : max(0, (int) $transition->duration_ms) / 1000;
            $segments[] = new StoryMediaSegment($clip->disk, $clip->path, $in, $out > $in ? $out : null, $type, $fade);

            $currentStart = max(0.0, $elapsed - $fade);
            $elapsed = $currentStart + max(0.0, $out - $in);
        }

        if ($segments === []) {
            throw new StoryException('The timeline needs at least one scene video to build the final video.', self::EMPTY, 422);
        }

        return [$segments, $overlays];
    }

    private function aspectRatio(StoryReel $reel): string
    {
        $ratio = $reel->workspace?->bible?->aspect_ratio;

        return is_string($ratio) && in_array($ratio, ['16:9', '9:16', '1:1', '4:3', '3:4', '21:9'], true) ? $ratio : '16:9';
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
                    'source_version_id' => $clip->sceneVersion?->uuid,
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
        $clips = is_array($render->timeline_snapshot['clips'] ?? null) ? $render->timeline_snapshot['clips'] : [];
        $durationMs = 0;
        foreach ($clips as $clip) {
            $durationMs += max(0, (int) ($clip['out_ms'] ?? 0) - (int) ($clip['in_ms'] ?? 0));
        }
        $export = StoryExport::query()
            ->where('story_final_render_id', $render->id)
            ->latest('id')
            ->first();

        return [
            'id' => $render->uuid,
            'timeline_id' => $render->timeline?->uuid ?? ($render->timeline_snapshot['timeline_id'] ?? null),
            'timeline_version' => $render->timeline_version,
            'status' => $render->status,
            'review_status' => $render->review_status ?: 'draft',
            'disk' => $ready ? $render->disk : null,
            'path' => $ready ? $render->path : null,
            'mime' => $ready ? $render->mime : null,
            'size_bytes' => $ready ? $render->size_bytes : null,
            'error_code' => $render->error_code,
            'has_file' => $ready,
            'output_url' => null,
            'clip_count' => count($clips),
            'duration_ms' => $durationMs,
            'created_at' => $render->created_at?->toIso8601String(),
            'latest_export' => $export === null ? null : [
                'id' => $export->uuid,
                'status' => $export->status,
                'filename' => $export->filename,
                'size' => $export->size,
            ],
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
