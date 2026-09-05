<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Lifecycle of a studio project asset record.
 */
enum ProjectAssetStatus: string
{
    use InteractsWithEnum;

    case Draft = 'draft';
    case Ready = 'ready';
    case Archived = 'archived';
}
