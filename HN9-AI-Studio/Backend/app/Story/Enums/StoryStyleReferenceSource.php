<?php

declare(strict_types=1);

namespace App\Story\Enums;

use App\Enums\Concerns\InteractsWithEnum;

enum StoryStyleReferenceSource: string
{
    use InteractsWithEnum;

    case Uploaded = 'uploaded';
    case Generated = 'generated';
}
