<?php

declare(strict_types=1);

namespace App\Story\Media;

use App\Story\Exceptions\StoryException;

final class StoryMediaException extends StoryException
{
    public const UNAVAILABLE = 'story_media_tools_unavailable';

    public const FAILED = 'story_media_build_failed';

    public static function unavailable(): self
    {
        return new self(
            'The video builder is not set up on this server yet, so videos cannot be joined or built.',
            self::UNAVAILABLE,
            503,
        );
    }

    public static function failed(string $detail = 'The video could not be built from these clips.'): self
    {
        return new self($detail, self::FAILED, 422);
    }
}
