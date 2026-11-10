<?php

declare(strict_types=1);

namespace App\Story\Support;

use App\Story\Models\StoryCharacter;

/**
 * Short text description of a locked character's look.
 */
final class StoryCharacterIdentityCard
{
    public static function build(StoryCharacter $character): string
    {
        $parts = [];
        $appearance = self::clean($character->appearance);
        $face = self::clean($character->face_description);
        $hair = self::clean($character->hair);
        $clothing = self::clean($character->clothing);
        $special = self::clean($character->special_details);
        $name = self::clean($character->name);

        if ($appearance !== '') {
            $parts[] = $appearance;
        }
        if ($face !== '') {
            $parts[] = 'face: '.$face;
        }
        if ($hair !== '') {
            $parts[] = 'hair: '.$hair;
        }
        if ($clothing !== '') {
            $parts[] = 'wearing: '.$clothing;
        }
        if ($special !== '') {
            $parts[] = $special;
        }

        $body = implode('; ', $parts);
        $text = $name !== '' ? ($body !== '' ? $name.': '.$body : $name) : $body;

        return self::limit($text, (int) config('story_character_lock.identity_max_chars', 300));
    }

    public static function clean(mixed $value): string
    {
        $text = preg_replace('/\p{C}+/u', '', (string) $value) ?? '';
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        return trim($text);
    }

    public static function limit(string $text, int $max): string
    {
        if ($max < 1 || mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > 0) {
            return rtrim(mb_substr($cut, 0, $space));
        }

        return rtrim($cut);
    }
}
