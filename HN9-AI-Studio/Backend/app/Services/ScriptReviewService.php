<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\ScriptReviewServiceInterface;
use App\Enums\ProjectStatus;
use App\Enums\ScriptReviewAction;
use App\Enums\ScriptStatus;
use App\Exceptions\ScriptWorkflowException;
use App\Models\Project;
use App\Models\Script;
use App\Models\ScriptReviewEvent;
use App\Models\User;
use App\Repositories\Contracts\ScriptRepositoryInterface;
use App\Repositories\Contracts\ScriptReviewEventRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Persisted script approval workflow. Actor identity always comes from the
 * authenticated user — never from a request payload.
 */
final readonly class ScriptReviewService implements ScriptReviewServiceInterface
{
    /**
     * @var list<string>
     */
    private const SCRIPT_RELATIONS = [
        'project',
        'generatedContent',
        'parentScript',
        'latestReviewEvent.user',
        'latestReworkEvent.user',
    ];

    public function __construct(
        private ScriptRepositoryInterface $scripts,
        private ScriptReviewEventRepositoryInterface $events,
        private ActivityLoggerInterface $activity,
    ) {}

    public function submit(Script $script, User $actor, ?string $comment = null): Script
    {
        $this->assertProjectAcceptsWorkflow($script->project ?? $script->project()->first());

        $current = $script->statusEnum();

        if (! $current->isSubmittable()) {
            throw ScriptWorkflowException::invalidTransition(
                $script->uuid,
                'submitted for review',
                $current->value,
            );
        }

        $action = $current === ScriptStatus::NeedsRework
            ? ScriptReviewAction::Resubmitted
            : ScriptReviewAction::SubmittedForReview;

        return $this->transition(
            $script,
            $actor,
            ScriptStatus::PendingReview,
            $action,
            $this->nullableComment($comment),
            $action === ScriptReviewAction::Resubmitted ? 'script.resubmitted' : 'script.submitted_for_review',
            $action === ScriptReviewAction::Resubmitted ? 'Script resubmitted for review' : 'Script submitted for review',
        );
    }

    public function approve(Script $script, User $actor, ?string $comment = null): Script
    {
        $this->assertProjectAcceptsWorkflow($script->project ?? $script->project()->first());

        $current = $script->statusEnum();

        if (! $current->isReviewable()) {
            throw ScriptWorkflowException::invalidTransition(
                $script->uuid,
                'approved',
                $current->value,
            );
        }

        return $this->transition(
            $script,
            $actor,
            ScriptStatus::Approved,
            ScriptReviewAction::Approved,
            $this->nullableComment($comment),
            'script.approved',
            'Script approved',
        );
    }

    public function requestRework(Script $script, User $actor, string $comment): Script
    {
        $this->assertProjectAcceptsWorkflow($script->project ?? $script->project()->first());

        $current = $script->statusEnum();

        if (! $current->isReviewable()) {
            throw ScriptWorkflowException::invalidTransition(
                $script->uuid,
                'sent back for rework',
                $current->value,
            );
        }

        $normalized = $this->nullableComment($comment);

        if ($normalized === null || mb_strlen($normalized) < 10) {
            throw ScriptWorkflowException::commentRequired();
        }

        return $this->transition(
            $script,
            $actor,
            ScriptStatus::NeedsRework,
            ScriptReviewAction::NeedsRework,
            $normalized,
            'script.needs_rework',
            'Script marked as needs rework',
        );
    }

    public function recordReworked(Script $script, User $actor): ScriptReviewEvent
    {
        $event = $this->events->create([
            'script_id' => $script->getKey(),
            'user_id' => $actor->getKey(),
            'action' => ScriptReviewAction::Reworked->value,
            'comment' => null,
            'from_status' => ScriptStatus::NeedsRework->value,
            'to_status' => ScriptStatus::NeedsRework->value,
            'created_at' => now(),
        ]);

        $this->activity->log('script.reworked', $script, $actor, 'Script reworked after review');

        return $event;
    }

    public function history(Script $script): Collection
    {
        return $this->events->listForScript($script, ['user']);
    }

    private function transition(
        Script $script,
        User $actor,
        ScriptStatus $to,
        ScriptReviewAction $action,
        ?string $comment,
        string $activityAction,
        string $activityDescription,
    ): Script {
        return DB::transaction(function () use ($script, $actor, $to, $action, $comment, $activityAction, $activityDescription): Script {
            $from = (string) $script->status;

            $updated = $this->scripts->update($script, ['status' => $to->value]);

            $this->events->create([
                'script_id' => $updated->getKey(),
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

            return $updated->load(self::SCRIPT_RELATIONS);
        });
    }

    private function assertProjectAcceptsWorkflow(?Project $project): void
    {
        if ($project === null) {
            throw ScriptWorkflowException::projectNotEditable('unknown');
        }

        $status = ProjectStatus::tryFrom((string) $project->status);

        if ($status === null || ! $status->isEditable()) {
            throw ScriptWorkflowException::projectNotEditable($project->uuid);
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
