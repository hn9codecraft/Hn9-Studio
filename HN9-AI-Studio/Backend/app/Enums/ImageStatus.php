<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Lifecycle of a studio image. Mirrors the `status` column on `images`.
 *
 * Review statuses follow the M10.3 script workflow and change only through
 * dedicated review actions. pending / processing / completed / failed remain
 * for compatibility with earlier studio rows.
 */
enum ImageStatus: string
{
    use InteractsWithEnum;

    case Draft = 'draft';
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Archived = 'archived';
    case PendingReview = 'pending_review';
    case NeedsRework = 'needs_rework';
    case Approved = 'approved';

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
        return in_array($this, [self::Draft, self::Pending, self::NeedsRework], true);
    }

    public function isReviewable(): bool
    {
        return $this === self::PendingReview;
    }

    public function allowsContentEdit(): bool
    {
        return in_array($this, [self::Draft, self::Pending, self::NeedsRework], true);
    }

    /**
     * A new generation is a new row. Pending review and archived rows cannot spawn one.
     */
    public function allowsRegeneration(): bool
    {
        return ! in_array($this, [self::PendingReview, self::Archived, self::Processing], true);
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Archived => 'Archived',
            self::PendingReview => 'Pending review',
            self::NeedsRework => 'Needs rework',
            self::Approved => 'Approved',
        };
    }
}
