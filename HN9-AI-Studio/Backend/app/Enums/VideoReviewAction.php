<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

enum VideoReviewAction: string
{
    use InteractsWithEnum;

    case SubmittedForReview = 'submitted_for_review';
    case Resubmitted = 'resubmitted';
    case Approved = 'approved';
    case NeedsRework = 'needs_rework';
    case Reworked = 'reworked';
}
