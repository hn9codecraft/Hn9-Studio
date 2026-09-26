<?php

declare(strict_types=1);

namespace App\Story\Enums;

use App\Enums\Concerns\InteractsWithEnum;

enum StoryPlanVersionStatus: string
{
    use InteractsWithEnum;

    case Generating = 'generating';
    case Completed = 'completed';
    case Failed = 'failed';
}
