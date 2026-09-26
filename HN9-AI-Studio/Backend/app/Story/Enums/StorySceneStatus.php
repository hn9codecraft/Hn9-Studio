<?php

declare(strict_types=1);

namespace App\Story\Enums;

enum StorySceneStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function allowsEdit(): bool
    {
        return $this !== self::Archived;
    }
}
