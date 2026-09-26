<?php

declare(strict_types=1);

namespace App\Story\Enums;

/**
 * Generic scene audio roles. These are request data, not vendor names.
 */
enum StoryAudioRole: string
{
    case Voice = 'voice';
    case Narration = 'narration';
    case Dialogue = 'dialogue';
    case Music = 'music';
    case Sfx = 'sfx';
    case Ambient = 'ambient';
    case Generated = 'generated';

    public function label(): string
    {
        return match ($this) {
            self::Voice => 'Voice',
            self::Narration => 'Narration',
            self::Dialogue => 'Dialogue',
            self::Music => 'Music',
            self::Sfx => 'SFX',
            self::Ambient => 'Ambient',
            self::Generated => 'Generated audio',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $role): string => $role->value,
            self::cases(),
        );
    }
}
