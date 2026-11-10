<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

class StoryQuickStartException extends StoryException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function conflict(array $context): self
    {
        return new self(
            message: 'This project already has story details or a plan. Confirm to add a new draft without removing them.',
            errorCode: 'story_quick_start_conflict',
            statusCode: 409,
            context: $context,
        );
    }

    public static function inProgress(): self
    {
        return new self(
            message: 'A quick start is already running for this project.',
            errorCode: 'story_quick_start_in_progress',
            statusCode: 409,
        );
    }

    public static function templateNotFound(): self
    {
        return new self(
            message: 'That story template does not exist.',
            errorCode: 'story_template_not_found',
            statusCode: 404,
        );
    }
}
