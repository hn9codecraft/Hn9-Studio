<?php

declare(strict_types=1);

namespace App\Story\Support;

final class StoryBibleAudioDefaults
{
    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return ['voice_enabled', 'music_enabled', 'sfx_enabled', 'voice_style', 'music_mood'];
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array<string, bool|string|null>
     */
    public static function normalize(?array $value): array
    {
        $source = $value ?? [];

        return [
            'voice_enabled' => self::boolOrNull($source['voice_enabled'] ?? null),
            'music_enabled' => self::boolOrNull($source['music_enabled'] ?? null),
            'sfx_enabled' => self::boolOrNull($source['sfx_enabled'] ?? null),
            'voice_style' => self::stringOrNull($source['voice_style'] ?? null),
            'music_mood' => self::stringOrNull($source['music_mood'] ?? null),
        ];
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
