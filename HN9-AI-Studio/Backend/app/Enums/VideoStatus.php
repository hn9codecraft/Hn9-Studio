<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Lifecycle of a studio video request. Mirrors the `status` column on `videos`.
 *
 * processing / completed / failed exist for a future provider job.
 * Until a provider is connected, user-facing writes stay on draft, pending, or archived.
 */
enum VideoStatus: string
{
    use InteractsWithEnum;

    case Draft = 'draft';
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Archived = 'archived';

    /**
     * Statuses a studio user may set while no video provider is connected.
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
}
