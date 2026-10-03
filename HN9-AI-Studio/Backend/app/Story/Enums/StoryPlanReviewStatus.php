<?php

declare(strict_types=1);

namespace App\Story\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Where a Story Plan Version stands between planning and production. Derived from the
 * generation status and the approval stamp; it is not stored separately.
 */
enum StoryPlanReviewStatus: string
{
    use InteractsWithEnum;

    case InProgress = 'in_progress';
    case Failed = 'failed';
    case ReadyForReview = 'ready_for_review';
    case Approved = 'approved';
}
