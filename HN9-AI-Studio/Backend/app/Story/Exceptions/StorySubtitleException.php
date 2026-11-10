<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

class StorySubtitleException extends StoryException
{
    public static function burnUnavailable(): self
    {
        return new self(
            message: 'This FFmpeg build cannot draw subtitles; install a full build with libass',
            errorCode: 'story_subtitle_burn_unavailable',
            statusCode: 422,
        );
    }

    public static function fontMissing(): self
    {
        return new self(
            message: 'Set STORY_SUBTITLE_FONT_FILE to a font that supports Gujarati, e.g. C:/Windows/Fonts/Nirmala.ttc or NotoSansGujarati-Regular.ttf',
            errorCode: 'story_subtitle_font_missing',
            statusCode: 422,
        );
    }

    public static function burnInProgress(): self
    {
        return new self(
            message: 'Subtitles are already being burned for this reel.',
            errorCode: 'story_subtitle_burn_in_progress',
            statusCode: 409,
        );
    }

    public static function renderInProgress(): self
    {
        return new self(
            message: 'A video is already being built for this reel.',
            errorCode: 'story_render_in_progress',
            statusCode: 409,
        );
    }

    public static function notReady(string $message = 'The render or the subtitle track is not ready.'): self
    {
        return new self($message, 'story_subtitle_not_ready', 422);
    }
}
