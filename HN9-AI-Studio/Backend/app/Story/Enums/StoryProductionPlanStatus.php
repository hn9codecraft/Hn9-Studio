<?php

declare(strict_types=1);

namespace App\Story\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Planning state only. Generation and review state belong to the units' versions, not to the plan.
 */
enum StoryProductionPlanStatus: string
{
    use InteractsWithEnum;

    case Active = 'active';
    case Superseded = 'superseded';
}
