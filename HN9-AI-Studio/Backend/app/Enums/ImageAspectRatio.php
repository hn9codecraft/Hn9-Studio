<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Supported aspect ratios for a studio image request.
 */
enum ImageAspectRatio: string
{
    use InteractsWithEnum;

    case Square = '1:1';
    case Landscape = '16:9';
    case Portrait = '9:16';
    case Standard = '4:3';
    case Tall = '3:4';
}
