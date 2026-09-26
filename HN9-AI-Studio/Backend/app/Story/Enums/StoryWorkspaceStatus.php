<?php

declare(strict_types=1);

namespace App\Story\Enums;

use App\Enums\Concerns\InteractsWithEnum;

enum StoryWorkspaceStatus: string
{
    use InteractsWithEnum;

    case Ready = 'ready';
}
