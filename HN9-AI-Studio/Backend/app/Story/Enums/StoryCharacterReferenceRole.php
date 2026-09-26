<?php

declare(strict_types=1);

namespace App\Story\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Extensible reference roles. M11.2 uses primary; later sprints may add face, full-body, etc.
 */
enum StoryCharacterReferenceRole: string
{
    use InteractsWithEnum;

    case Primary = 'primary';
    case Face = 'face';
    case FullBody = 'full_body';
    case SideProfile = 'side_profile';
    case Expression = 'expression';
    case Outfit = 'outfit';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primary',
            self::Face => 'Face',
            self::FullBody => 'Full body',
            self::SideProfile => 'Side profile',
            self::Expression => 'Expression',
            self::Outfit => 'Outfit',
        };
    }
}
