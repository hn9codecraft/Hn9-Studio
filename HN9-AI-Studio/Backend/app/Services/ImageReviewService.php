<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\ImageReviewServiceInterface;
use App\Enums\ImageReviewAction;
use App\Enums\ImageStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ImageWorkflowException;
use App\Models\Image;
use App\Models\ImageReviewEvent;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Contracts\ImageRepositoryInterface;
use App\Repositories\Contracts\ImageReviewEventRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Image approval workflow. Same states as the script review trail.
 * The actor is always the authenticated user.
 */
final readonly class ImageReviewService implements ImageReviewServiceInterface
{
    /**
     * @var list<string>
     */
    private const RELATIONS = [
        'project',
        'script',
        'parentImage',
        'generatedContent',
        'generatedAsset',
        'file',
        'latestReviewEvent.user',
        'latestReworkEvent.user',
    ];

    public function __construct(
        private ImageRepositoryInterface $images,
        private ImageReviewEventRepositoryInterface $events,
        private ActivityLoggerInterface $activity,
    ) {}

    public function submit(Image $image, User $actor, ?string $comment = null): Image
    {
        $this->assertProjectAcceptsWorkflow($image->project ?? $image->project()->first());

        $current = $image->statusEnum();

        if (! $current->isSubmittable()) {
            throw ImageWorkflowException::invalidTransition($image->uuid, 'submitted for review', $current->value);
        }

        $action = $current === ImageStatus::NeedsRework
            ? ImageReviewAction::Resubmitted
            : ImageReviewAction::SubmittedForReview;

        return $this->transition(
            $image,
            $actor,
            ImageStatus::PendingReview,
            $action,
            $this->nullableComment($comment),
            $action === ImageReviewAction::Resubmitted ? 'image.resubmitted' : 'image.submitted_for_review',
            $action === ImageReviewAction::Resubmitted ? 'Image resubmitted for review' : 'Image submitted for review',
        );
    }

    public function approve(Image $image, User $actor, ?string $comment = null): Image
    {
        $this->assertProjectAcceptsWorkflow($image->project ?? $image->project()->first());

        $current = $image->statusEnum();

        if (! $current->isReviewable()) {
            throw ImageWorkflowException::invalidTransition($image->uuid, 'approved', $current->value);
        }

        return $this->transition(
            $image,
            $actor,
            ImageStatus::Approved,
            ImageReviewAction::Approved,
            $this->nullableComment($comment),
            'image.approved',
            'Image approved',
        );
    }

    public function requestRework(Image $image, User $actor, string $comment): Image
    {
        $this->assertProjectAcceptsWorkflow($image->project ?? $image->project()->first());

        $current = $image->statusEnum();

        if (! $current->isReviewable()) {
            throw ImageWorkflowException::invalidTransition($image->uuid, 'sent back for rework', $current->value);
        }

        $normalized = $this->nullableComment($comment);

        if ($normalized === null || mb_strlen($normalized) < 10) {
            throw ImageWorkflowException::commentRequired();
        }

        return $this->transition(
            $image,
            $actor,
            ImageStatus::NeedsRework,
            ImageReviewAction::NeedsRework,
            $normalized,
            'image.needs_rework',
            'Image marked as needs rework',
        );
    }

    public function recordReworked(Image $image, User $actor): ImageReviewEvent
    {
        $event = $this->events->create([
            'image_id' => $image->getKey(),
            'user_id' => $actor->getKey(),
            'action' => ImageReviewAction::Reworked->value,
            'comment' => null,
            'from_status' => ImageStatus::NeedsRework->value,
            'to_status' => ImageStatus::NeedsRework->value,
            'created_at' => now(),
        ]);

        $this->activity->log('image.reworked', $image, $actor, 'Image reworked after review');

        return $event;
    }

    public function history(Image $image): Collection
    {
        return $this->events->listForImage($image, ['user']);
    }

    private function transition(
        Image $image,
        User $actor,
        ImageStatus $to,
        ImageReviewAction $action,
        ?string $comment,
        string $activityAction,
        string $activityDescription,
    ): Image {
        return DB::transaction(function () use ($image, $actor, $to, $action, $comment, $activityAction, $activityDescription): Image {
            $from = (string) $image->status;
            $updated = $this->images->update($image, ['status' => $to->value]);

            $this->events->create([
                'image_id' => $updated->getKey(),
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
            throw ImageWorkflowException::projectNotEditable('unknown');
        }

        $status = ProjectStatus::tryFrom((string) $project->status);

        if ($status === null || ! $status->isEditable()) {
            throw ImageWorkflowException::projectNotEditable($project->uuid);
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
