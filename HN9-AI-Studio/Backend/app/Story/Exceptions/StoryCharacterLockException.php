<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

final class StoryCharacterLockException extends StoryException
{
    public static function needsReference(): self
    {
        return new self(
            message: 'Approve a reference picture for this character first.',
            errorCode: 'story_character_lock_needs_reference',
            statusCode: 422,
        );
    }

    public static function locked(): self
    {
        return new self(
            message: 'Unlock this character to change its look.',
            errorCode: 'story_character_locked',
            statusCode: 409,
        );
    }

    public static function referenceLocked(): self
    {
        return new self(
            message: 'Unlock this character before changing the locked picture.',
            errorCode: 'story_character_reference_locked',
            statusCode: 409,
        );
    }
}
