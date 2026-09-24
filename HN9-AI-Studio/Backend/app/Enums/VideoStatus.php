<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Lifecycle of a studio video. Generation states and M10.3 review states
 * share one column so there is not a second workflow architecture.
 */
enum VideoStatus: string
{
    use InteractsWithEnum;

    case Draft = 'draft';
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case PendingReview = 'pending_review';
    case NeedsRework = 'needs_rework';
    case Approved = 'approved';
    case Archived = 'archived';

    /**
     * Statuses a studio user may set through create/update payloads.
     *
     * @return list<string>
     */
    public static function assignableValues(): array
    {
        return [
            self::Draft->value,
            self::Pending->value,
            self::Archived->value,
        ];
    }

    public function isSubmittable(): bool
    {
        return in_array($this, [self::Completed, self::NeedsRework], true);
    }

    public function isReviewable(): bool
    {
        return $this === self::PendingReview;
    }

    public function allowsContentEdit(): bool
    {
        return in_array($this, [self::Draft, self::Pending, self::Failed, self::NeedsRework], true);
    }

    public function allowsRegeneration(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::NeedsRework, self::Approved], true);
    }

    public function isInFlight(): bool
    {
        return in_array($this, [self::Pending, self::Processing], true);
    }

    public function hasPlayableOutput(): bool
    {
        return in_array($this, [self::Completed, self::PendingReview, self::NeedsRework, self::Approved], true);
    }
}
