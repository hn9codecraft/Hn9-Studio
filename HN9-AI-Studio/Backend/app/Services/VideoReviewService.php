<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\VideoReviewServiceInterface;
use App\Enums\ProjectStatus;
use App\Enums\VideoReviewAction;
use App\Enums\VideoStatus;
use App\Exceptions\VideoWorkflowException;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoReviewEvent;
use App\Repositories\Contracts\VideoRepositoryInterface;
use App\Repositories\Contracts\VideoReviewEventRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Video approval workflow. Same states as the script review trail.
 * The actor is always the authenticated user.
 */
final readonly class VideoReviewService implements VideoReviewServiceInterface
{
    /**
     * @var list<string>
     */
    private const RELATIONS = [
        'project',
        'script',
        'image',
        'parentVideo',
        'generatedAsset',
        'file',
        'latestReviewEvent.user',
        'latestReworkEvent.user',
    ];

    public function __construct(
        private VideoRepositoryInterface $videos,
        private VideoReviewEventRepositoryInterface $events,
        private ActivityLoggerInterface $activity,
    ) {}

    public function submit(Video $video, User $actor, ?string $comment = null): Video
    {
        $this->assertProjectAcceptsWorkflow($video->project ?? $video->project()->first());

        $current = $video->statusEnum();

        if (! $current->isSubmittable()) {
            throw VideoWorkflowException::invalidTransition($video->uuid, 'submitted for review', $current->value);
        }

        $action = $current === VideoStatus::NeedsRework
            ? VideoReviewAction::Resubmitted
            : VideoReviewAction::SubmittedForReview;

        return $this->transition(
            $video,
            $actor,
            VideoStatus::PendingReview,
            $action,
            $this->nullableComment($comment),
            $action === VideoReviewAction::Resubmitted ? 'video.resubmitted' : 'video.submitted_for_review',
            $action === VideoReviewAction::Resubmitted ? 'Video resubmitted for review' : 'Video submitted for review',
        );
    }

    public function approve(Video $video, User $actor, ?string $comment = null): Video
    {
        $this->assertProjectAcceptsWorkflow($video->project ?? $video->project()->first());

        $current = $video->statusEnum();

        if (! $current->isReviewable()) {
            throw VideoWorkflowException::invalidTransition($video->uuid, 'approved', $current->value);
        }

        return $this->transition(
            $video,
            $actor,
            VideoStatus::Approved,
            VideoReviewAction::Approved,
            $this->nullableComment($comment),
            'video.approved',
            'Video approved',
        );
    }

    public function requestRework(Video $video, User $actor, string $comment): Video
    {
        $this->assertProjectAcceptsWorkflow($video->project ?? $video->project()->first());

        $current = $video->statusEnum();

        if (! $current->isReviewable()) {
            throw VideoWorkflowException::invalidTransition($video->uuid, 'sent back for rework', $current->value);
        }

        $normalized = $this->nullableComment($comment);

        if ($normalized === null || mb_strlen($normalized) < 10) {
            throw VideoWorkflowException::commentRequired();
        }

        return $this->transition(
            $video,
            $actor,
            VideoStatus::NeedsRework,
            VideoReviewAction::NeedsRework,
            $normalized,
            'video.needs_rework',
            'Video marked as needs rework',
        );
    }

    public function recordReworked(Video $video, User $actor): VideoReviewEvent
    {
        $event = $this->events->create([
            'video_id' => $video->getKey(),
            'user_id' => $actor->getKey(),
            'action' => VideoReviewAction::Reworked->value,
            'comment' => null,
            'from_status' => VideoStatus::NeedsRework->value,
            'to_status' => VideoStatus::NeedsRework->value,
            'created_at' => now(),
        ]);

        $this->activity->log('video.reworked', $video, $actor, 'Video reworked after review');

        return $event;
    }

    public function history(Video $video): Collection
    {
        return $this->events->listForVideo($video, ['user']);
    }

    private function transition(
        Video $video,
        User $actor,
        VideoStatus $to,
        VideoReviewAction $action,
        ?string $comment,
        string $activityAction,
        string $activityDescription,
    ): Video {
        return DB::transaction(function () use ($video, $actor, $to, $action, $comment, $activityAction, $activityDescription): Video {
            $from = (string) $video->status;
            $updated = $this->videos->update($video, ['status' => $to->value]);

            $this->events->create([
                'video_id' => $updated->getKey(),
                'user_id' => $actor->getKey(),
                'action' => $action->value,
                'comment' => $comment,
                'from_status' => $from,
                'to_status' => $to->value,
                'created_at' => now(),
            ]);

            $this->activity->log($activityAction, $updated, $actor, $activityDescription, [
                'from_status' => $from,
                'to_status' => $to->value,
                'review_action' => $action->value,
            ]);

            return $updated->load(self::RELATIONS);
        });
    }

    private function assertProjectAcceptsWorkflow(?Project $project): void
    {
        if ($project === null) {
            throw VideoWorkflowException::projectNotEditable('unknown');
        }

        $status = ProjectStatus::tryFrom((string) $project->status);

        if ($status === null || ! $status->isEditable()) {
            throw VideoWorkflowException::projectNotEditable($project->uuid);
        }
    }

    private function nullableComment(?string $comment): ?string
    {
        if ($comment === null) {
            return null;
        }

        $trimmed = trim($comment);

        return $trimmed === '' ? null : $trimmed;
    }
}
