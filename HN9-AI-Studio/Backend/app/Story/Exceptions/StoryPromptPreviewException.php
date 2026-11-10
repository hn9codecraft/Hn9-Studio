<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

final class StoryPromptPreviewException extends StoryException
{
    public static function stale(): self
    {
        return new self(
            message: 'The prompt, picture, price or provider changed since your preview. Check the preview again.',
            errorCode: 'story_prompt_preview_stale',
            statusCode: 409,
        );
    }

    public static function emptyPrompt(): self
    {
        return new self(
            message: 'Write the scene part of the prompt before saving it.',
            errorCode: 'story_unit_prompt_invalid',
            statusCode: 422,
        );
    }

    public static function promptTooLong(): self
    {
        return new self(
            message: 'The scene part of the prompt is too long.',
            errorCode: 'story_unit_prompt_invalid',
            statusCode: 422,
        );
    }
}
