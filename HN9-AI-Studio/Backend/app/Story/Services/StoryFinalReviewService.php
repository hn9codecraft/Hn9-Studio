<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Models\User;
use App\Story\Enums\StoryReviewStatus;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Exceptions\StoryReviewException;
use App\Story\Models\StoryFinalRender;
use App\Story\Models\StoryFinalRenderReview;
use App\Story\Models\StoryReel;
use App\Story\Models\StorySceneVersion;
use Illuminate\Support\Facades\Storage;

/**
 * Submit, approve, and rework a completed final render. No provider calls and no new video.
 */
final class StoryFinalReviewService
{
    /**
     * @return array<string, mixed>
     */
    public function submit(Project $project, string $reelUuid, string $renderUuid, User $actor, ?string $comment): array
    {
        $render = $this->find($project, $reelUuid, $renderUuid);
        $this->assertCompleted($render);
        $status = $this->reviewStatus($render);
        if (! $status->isSubmittable()) {
            throw StoryReviewException::invalidTransition($status->value, 'submitted for review');
        }

        $this->event($render, $actor, 'submitted', $comment, null, null);
        $render->forceFill(['review_status' => StoryReviewStatus::PendingReview->value])->save();

        return $this->payload($render->fresh() ?? $render);
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(Project $project, string $reelUuid, string $renderUuid, User $actor, ?string $comment): array
    {
        $render = $this->find($project, $reelUuid, $renderUuid);
        $this->assertCompleted($render);
        $status = $this->reviewStatus($render);
        if (! $status->isReviewable()) {
            throw StoryReviewException::invalidTransition($status->value, StoryReviewStatus::Approved->value);
        }

        $this->event($render, $actor, 'approved', $comment, null, null);
        $render->forceFill(['review_status' => StoryReviewStatus::Approved->value])->save();

        return $this->payload($render->fresh() ?? $render);
    }

    /**
     * @return array<string, mixed>
     */
    public function rework(
        Project $project,
        string $reelUuid,
        string $renderUuid,
        User $actor,
        string $comment,
        string $targetKind,
        string $targetId,
    ): array {
        $render = $this->find($project, $reelUuid, $renderUuid);
        $this->assertCompleted($render);
        $status = $this->reviewStatus($render);
        if (! $status->isReviewable()) {
            throw StoryReviewException::invalidTransition($status->value, StoryReviewStatus::NeedsRework->value);
        }
        $comment = trim($comment);
        if ($comment === '') {
            throw new StoryException('A rework comment is required.', 'story_review_comment_required', 422);
        }
        $this->assertTarget($render, $targetKind, $targetId);

        $this->event($render, $actor, 'needs_rework', $comment, $targetKind, $targetId);
        $render->forceFill(['review_status' => StoryReviewStatus::NeedsRework->value])->save();

        return $this->payload($render->fresh() ?? $render);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Project $project, string $reelUuid, string $renderUuid): array
    {
        return $this->payload($this->find($project, $reelUuid, $renderUuid));
    }

    private function assertCompleted(StoryFinalRender $render): void
    {
        if ($render->status !== StoryVideoJobStatus::Completed->value || $render->path === null || $render->disk === null) {
            throw new StoryException('Only a completed render can be reviewed.', 'story_render_not_ready', 422);
        }
        if (! Storage::disk($render->disk)->exists($render->path)) {
            throw new StoryException('Only a completed render can be reviewed.', 'story_render_not_ready', 422);
        }
    }

    private function assertTarget(StoryFinalRender $render, string $targetKind, string $targetId): void
    {
        $snapshot = is_array($render->timeline_snapshot) ? $render->timeline_snapshot : [];
        if ($targetKind === 'timeline') {
            $timelineId = $render->timeline?->uuid ?? ($snapshot['timeline_id'] ?? null);
            if ($timelineId !== $targetId) {
                throw new StoryException('Rework must point at this render timeline or one of its scene versions.', 'story_review_invalid_target', 422);
            }

            return;
        }
        if ($targetKind !== 'scene_version') {
            throw new StoryException('Rework must point at this render timeline or one of its scene versions.', 'story_review_invalid_target', 422);
        }

        $known = [];
        foreach ($snapshot['clips'] ?? [] as $clip) {
            if (is_array($clip) && isset($clip['source_version_id']) && is_string($clip['source_version_id'])) {
                $known[$clip['source_version_id']] = true;
            }
        }
        if (! isset($known[$targetId])) {
            $version = StorySceneVersion::query()->where('uuid', $targetId)->first();
            $onTimeline = $version instanceof StorySceneVersion
                && $render->timeline?->clips()->where('story_scene_version_id', $version->id)->exists();
            if (! $onTimeline) {
                throw new StoryException('Rework must point at this render timeline or one of its scene versions.', 'story_review_invalid_target', 422);
            }
        }
    }

    private function event(
        StoryFinalRender $render,
        User $actor,
        string $action,
        ?string $comment,
        ?string $targetKind,
        ?string $targetId,
    ): void {
        StoryFinalRenderReview::query()->create([
            'story_final_render_id' => $render->id,
            'user_id' => $actor->id,
            'action' => $action,
            'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
            'target_kind' => $targetKind,
            'target_id' => $targetId,
        ]);
    }

    private function reviewStatus(StoryFinalRender $render): StoryReviewStatus
    {
        return StoryReviewStatus::tryFrom((string) $render->review_status) ?? StoryReviewStatus::Draft;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(StoryFinalRender $render): array
    {
        $ready = $render->status === StoryVideoJobStatus::Completed->value
            && is_string($render->path)
            && $render->disk !== null
            && Storage::disk($render->disk)->exists($render->path);

        return [
            'id' => $render->uuid,
            'status' => $render->status,
            'review_status' => $this->reviewStatus($render)->value,
            'path' => $ready ? $render->path : null,
            'has_file' => $ready,
            'output_url' => null,
            'events' => StoryFinalRenderReview::query()
                ->where('story_final_render_id', $render->id)
                ->orderBy('id')
                ->get()
                ->map(static function (StoryFinalRenderReview $event): array {
                    return [
                        'id' => $event->uuid,
                        'action' => $event->action,
                        'comment' => $event->comment,
                        'target_kind' => $event->target_kind,
                        'target_id' => $event->target_id,
                    ];
                })
                ->all(),
        ];
    }

    private function find(Project $project, string $reelUuid, string $renderUuid): StoryFinalRender
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

        $render = StoryFinalRender::query()
            ->with('timeline')
            ->where('uuid', $renderUuid)
            ->where('story_reel_id', $reel->id)
            ->first();
        if (! $render instanceof StoryFinalRender) {
            throw StoryException::notFound('Render');
        }

        return $render;
    }
}
