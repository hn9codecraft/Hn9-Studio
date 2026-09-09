<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Deterministic Action Center priority from a real studio status.
 *
 * failed → high; pending/processing → medium; draft → low.
 */
enum DashboardActionPriority: string
{
    use InteractsWithEnum;

    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
