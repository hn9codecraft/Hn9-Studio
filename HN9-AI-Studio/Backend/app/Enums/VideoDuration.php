<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Supported clip lengths for a studio video request, in seconds.
 */
enum VideoDuration: int
{
    use InteractsWithEnum;

    case Five = 5;
    case Ten = 10;
    case Fifteen = 15;
    case Thirty = 30;

    public function label(): string
    {
        return $this->value.' seconds';
    }
}
