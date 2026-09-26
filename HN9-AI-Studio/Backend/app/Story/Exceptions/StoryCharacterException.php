<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

final class StoryCharacterException extends StoryException
{
    public static function invalidTransition(string $from, string $action): self
    {
        return new self(
            message: "This character reference cannot be {$action} from status '{$from}'.",
            errorCode: 'story_character_reference_invalid_transition',
            statusCode: 422,
            context: ['from' => $from, 'action' => $action],
        );
    }

    public static function storageFailed(?string $detail = null): self
    {
        return new self(
            message: $detail ?? 'The character reference could not be stored.',
            errorCode: 'story_character_reference_storage_failed',
            statusCode: 422,
        );
    }

    public static function invalidImage(): self
    {
        return new self(
            message: 'The uploaded file is not a readable image.',
            errorCode: 'story_character_reference_invalid_image',
            statusCode: 422,
        );
    }

    public static function generationFailed(string $message): self
    {
        return new self(
            message: $message,
            errorCode: 'story_character_reference_generation_failed',
            statusCode: 502,
        );
    }

    public static function notReady(): self
    {
        return new self(
            message: 'The character reference file is not available.',
            errorCode: 'story_character_reference_not_ready',
            statusCode: 404,
        );
    }

    public static function archived(): self
    {
        return new self(
            message: 'This character is archived and cannot be modified.',
            errorCode: 'story_character_archived',
            statusCode: 422,
        );
    }
}
