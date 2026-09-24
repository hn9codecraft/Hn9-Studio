<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Append-only review events recorded against a studio script.
 */
enum ScriptReviewAction: string
{
    use InteractsWithEnum;

    case SubmittedForReview = 'submitted_for_review';
    case Resubmitted = 'resubmitted';
    case Approved = 'approved';
    case NeedsRework = 'needs_rework';
    case Reworked = 'reworked';

    public function label(): string
    {
        return match ($this) {
            self::SubmittedForReview => 'Submitted for review',
            self::Resubmitted => 'Resubmitted for review',
            self::Approved => 'Approved',
            self::NeedsRework => 'Needs rework',
            self::Reworked => 'Reworked',
        };
    }
}
