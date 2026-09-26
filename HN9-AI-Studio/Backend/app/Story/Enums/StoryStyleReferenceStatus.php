<?php

declare(strict_types=1);

namespace App\Story\Enums;

use App\Enums\Concerns\InteractsWithEnum;

enum StoryStyleReferenceStatus: string
{
    use InteractsWithEnum;

    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Archived = 'archived';

    public function isSubmittable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    public function isReviewable(): bool
    {
        return $this === self::PendingReview;
    }

    public function isArchivable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected, self::Approved], true);
    }
}
