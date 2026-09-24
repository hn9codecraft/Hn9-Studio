<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

enum ExportStatus: string
{
    use InteractsWithEnum;

    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isInFlight(): bool
    {
        return in_array($this, [self::Queued, self::Processing], true);
    }

    public function isDownloadable(): bool
    {
        return $this === self::Completed;
    }
}
