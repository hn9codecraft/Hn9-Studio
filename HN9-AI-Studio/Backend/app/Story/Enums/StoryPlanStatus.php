<?php

declare(strict_types=1);

namespace App\Story\Enums;

use App\Enums\Concerns\InteractsWithEnum;

enum StoryPlanStatus: string
{
    use InteractsWithEnum;

    case Draft = 'draft';
    case Generating = 'generating';
    case Completed = 'completed';
    case Failed = 'failed';
    case Archived = 'archived';

    public function allowsGenerate(): bool
    {
        return in_array($this, [self::Draft, self::Completed, self::Failed], true);
    }
}
