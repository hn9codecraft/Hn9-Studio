<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

final class StoryReviewException extends StoryException
{
    public static function invalidTransition(string $from, string $action): self
    {
        return new self(
            message: "This version cannot be {$action} from status '{$from}'.",
            errorCode: 'story_review_invalid_transition',
            statusCode: 422,
            context: ['from' => $from, 'action' => $action],
        );
    }

    public static function unit(string $message): self
    {
        return new self(
            message: $message,
            errorCode: 'story_review_invalid_transition',
            statusCode: 422,
        );
    }

    public static function sound(string $message): self
    {
        return new self(
            message: $message,
            errorCode: 'story_review_invalid_transition',
            statusCode: 422,
        );
    }
}
