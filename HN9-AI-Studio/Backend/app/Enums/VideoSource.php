<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

enum VideoSource: string
{
    use InteractsWithEnum;

    case Manual = 'manual';
    case Ai = 'ai';
}
