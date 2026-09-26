<?php

declare(strict_types=1);

namespace App\Story\Enums;

use App\Enums\Concerns\InteractsWithEnum;

enum StoryCharacterStatus: string
{
    use InteractsWithEnum;

    case Draft = 'draft';
    case Archived = 'archived';

    public function allowsEdit(): bool
    {
        return $this === self::Draft;
    }
}
