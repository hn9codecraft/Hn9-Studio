<?php

declare(strict_types=1);

namespace App\Story\Enums;

enum StoryTimelineTransitionType: string
{
    case Cut = 'cut';
    case Dissolve = 'dissolve';
    case Fade = 'fade';
}
