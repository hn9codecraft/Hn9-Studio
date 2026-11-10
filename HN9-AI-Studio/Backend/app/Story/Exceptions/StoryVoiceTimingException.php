<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

class StoryVoiceTimingException extends StoryException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function requiresRegeneration(int $count, array $estimate): self
    {
        return new self(
            message: 'Changed scenes already have video clips. Confirm that those scenes need new clips. Nothing is generated now.',
            errorCode: 'story_voice_timing_requires_regeneration',
            statusCode: 409,
            context: [
                'scenes_with_unit_versions' => $count,
                'regeneration_estimate' => $estimate,
            ],
        );
    }

    public static function inProgress(): self
    {
        return new self(
            message: 'Scene lengths are already being matched for this project.',
            errorCode: 'story_voice_timing_in_progress',
            statusCode: 409,
        );
    }

    public static function mediaUnavailable(): self
    {
        return new self(
            message: 'FFmpeg/ffprobe not found',
            errorCode: 'story_media_unavailable',
            statusCode: 422,
        );
    }
}
