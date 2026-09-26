<?php

declare(strict_types=1);

namespace App\Story\Enums;

use App\Enums\Concerns\InteractsWithEnum;

enum StoryBibleAspectRatio: string
{
    use InteractsWithEnum;

    case Landscape = '16:9';
    case Portrait = '9:16';
}
