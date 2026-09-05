<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Lifecycle of a studio script. Mirrors the `status` column on `scripts`.
 */
enum ScriptStatus: string
{
    use InteractsWithEnum;

    case Draft = 'draft';
    case Ready = 'ready';
    case Archived = 'archived';
}
