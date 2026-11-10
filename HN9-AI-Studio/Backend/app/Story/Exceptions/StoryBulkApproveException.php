<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

final class StoryBulkApproveException extends StoryException
{
    public static function kind(): self
    {
        return new self(
            message: 'Choose clips, voiceovers, or reference pictures to approve.',
            errorCode: 'story_bulk_kind_invalid',
            statusCode: 422,
        );
    }

    public static function selection(): self
    {
        return new self(
            message: 'Choose between 1 and 50 items.',
            errorCode: 'story_bulk_selection_invalid',
            statusCode: 422,
        );
    }

    public static function changed(): self
    {
        return new self(
            message: 'The selection changed. Check the list and try again.',
            errorCode: 'story_bulk_selection_changed',
            statusCode: 409,
        );
    }

    public static function inProgress(): self
    {
        return new self(
            message: 'Another approve is already running for this list.',
            errorCode: 'story_bulk_in_progress',
            statusCode: 409,
        );
    }
}
