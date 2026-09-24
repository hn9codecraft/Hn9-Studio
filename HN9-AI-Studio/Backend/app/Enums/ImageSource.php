<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Origin of a studio image. Manual rows are prompt drafts; AI rows are created
 * from a real provider response and are never overwritten by a later generation.
 */
enum ImageSource: string
{
    use InteractsWithEnum;

    case Manual = 'manual';
    case Ai = 'ai';
}
