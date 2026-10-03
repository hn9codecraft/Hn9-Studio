<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

class StoryRuntimeException extends StoryException
{
    public static function archived(string $resource = 'Resource'): self
    {
        return new self(
            message: "{$resource} is archived and cannot be modified.",
            errorCode: 'story_runtime_archived',
            statusCode: 422,
        );
    }

    public static function invalidDuration(string $detail): self
    {
        return new self(
            message: $detail,
            errorCode: 'story_scene_invalid_duration',
            statusCode: 422,
        );
    }

    public static function materializationFailed(string $detail, ?\Throwable $previous = null): self
    {
        return new self(
            message: $detail,
            errorCode: 'story_materialization_failed',
            statusCode: 422,
            previous: $previous,
        );
    }

    public static function alreadyMaterialized(): self
    {
        return new self(
            message: 'This plan version has already been materialized into a reel.',
            errorCode: 'story_already_materialized',
            statusCode: 409,
        );
    }

    public static function invalidReorder(): self
    {
        return new self(
            message: 'Reorder payload is invalid for this workspace or reel.',
            errorCode: 'story_runtime_invalid_reorder',
            statusCode: 422,
        );
    }
}
