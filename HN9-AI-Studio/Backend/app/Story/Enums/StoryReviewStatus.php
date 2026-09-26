<?php

declare(strict_types=1);

namespace App\Story\Enums;

enum StoryReviewStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case NeedsRework = 'needs_rework';

    public function isSubmittable(): bool
    {
        return in_array($this, [self::Draft, self::NeedsRework], true);
    }

    public function isReviewable(): bool
    {
        return $this === self::PendingReview;
    }
}
