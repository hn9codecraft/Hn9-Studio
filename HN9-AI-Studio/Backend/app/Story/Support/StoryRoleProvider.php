<?php

declare(strict_types=1);

namespace App\Story\Support;

/**
 * Optional per-role provider. An empty value keeps the existing default route.
 */
final class StoryRoleProvider
{
    public static function text(?string $requested): ?string
    {
        return self::chosen($requested, 'ai.story_text_provider');
    }

    public static function image(?string $requested): ?string
    {
        return self::chosen($requested, 'ai.story_image_provider');
    }

    private static function chosen(?string $requested, string $path): ?string
    {
        if (is_string($requested) && trim($requested) !== '') {
            return trim($requested);
        }
        $configured = config($path);

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
    }
}
