<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

final class StoryGenerationNotAvailableException extends StoryException
{
    public static function make(): self
    {
        return new self(
            message: 'Video creation is not connected yet. An administrator needs to connect a video service before videos can be made.',
            errorCode: 'story_generation_not_available',
            statusCode: 501,
        );
    }
}
