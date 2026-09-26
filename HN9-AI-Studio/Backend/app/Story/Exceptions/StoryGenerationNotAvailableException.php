<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

final class StoryGenerationNotAvailableException extends StoryException
{
    public static function make(): self
    {
        return new self(
            message: 'Story video generation is not available in this foundation release.',
            errorCode: 'story_generation_not_available',
            statusCode: 501,
        );
    }
}
