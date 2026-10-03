<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

final class StoryGenerationNotAvailableException extends StoryException
{
    public static function make(): self
    {
        return new self(
            message: 'Video creation is not connected yet. Connect a video provider in Settings to create this video.',
            errorCode: 'story_generation_not_available',
            statusCode: 501,
        );
    }
}
