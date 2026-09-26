<?php

declare(strict_types=1);

namespace App\Story\Enums;

use App\Enums\Concerns\InteractsWithEnum;

/**
 * Extensible style reference roles. M11.3 uses primary.
 */
enum StoryStyleReferenceRole: string
{
    use InteractsWithEnum;

    case Primary = 'primary';
    case Mood = 'mood';
    case Environment = 'environment';
    case Lighting = 'lighting';
    case Palette = 'palette';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primary',
            self::Mood => 'Mood',
            self::Environment => 'Environment',
            self::Lighting => 'Lighting',
            self::Palette => 'Palette',
        };
    }
}
