<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Supported aspect ratios for a studio video request.
 */
enum VideoAspectRatio: string
{
    use InteractsWithEnum;

    case Landscape = '16:9';
    case Portrait = '9:16';
    case Square = '1:1';
}
