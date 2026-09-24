<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Lifecycle of a studio script. Mirrors the `status` column on `scripts`.
 *
 * Workflow statuses (pending_review, needs_rework, approved) may only change
 * through dedicated review actions — never via the normal update payload.
 *
 * Legacy assignable statuses (draft, ready, archived) remain for manual
 * studio organisation and M10.2 compatibility.
 */
enum ScriptStatus: string
{
    use InteractsWithEnum;

    case Draft = 'draft';
    case Ready = 'ready';
    case PendingReview = 'pending_review';
    case NeedsRework = 'needs_rework';
    case Approved = 'approved';
    case Archived = 'archived';

    /**
     * Statuses a user may set through create/update payloads.
     *
     * @return list<string>
     */
    public static function assignableValues(): array
    {
        return [
            self::Draft->value,
            self::Ready->value,
            self::Archived->value,
        ];
    }

    /**
     * Statuses that may be submitted (or resubmitted) for review.
     */
    public function isSubmittable(): bool
    {
        return in_array($this, [self::Draft, self::Ready, self::NeedsRework], true);
    }

    /**
     * Statuses that accept a reviewer decision.
     */
    public function isReviewable(): bool
    {
        return $this === self::PendingReview;
    }

    /**
     * Title/body may be edited in these states.
     */
    public function allowsContentEdit(): bool
    {
        return in_array($this, [self::Draft, self::Ready, self::NeedsRework], true);
    }

    /**
     * Whether a new AI variation may be spawned from this script.
     * Approved scripts are not mutated; a new draft variation is allowed.
     */
    public function allowsRegeneration(): bool
    {
        return ! in_array($this, [self::PendingReview, self::Archived], true);
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    public function isWorkflowLocked(): bool
    {
        return in_array($this, [self::PendingReview, self::Approved], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ready => 'Ready',
            self::PendingReview => 'Pending review',
            self::NeedsRework => 'Needs rework',
            self::Approved => 'Approved',
            self::Archived => 'Archived',
        };
    }
}
