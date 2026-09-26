<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

final class StoryPlannerException extends StoryException
{
    public static function invalidDuration(string $message): self
    {
        return new self(
            message: $message,
            errorCode: 'story_plan_invalid_duration',
            statusCode: 422,
        );
    }

    public static function malformedOutput(string $detail): self
    {
        return new self(
            message: 'The story planner returned an invalid structured plan.',
            errorCode: 'story_plan_malformed_output',
            statusCode: 502,
            context: ['detail' => $detail],
        );
    }

    public static function generationFailed(string $message): self
    {
        return new self(
            message: $message,
            errorCode: 'story_plan_generation_failed',
            statusCode: 502,
        );
    }

    public static function notGeneratable(string $status): self
    {
        return new self(
            message: "This story plan cannot be generated from status '{$status}'.",
            errorCode: 'story_plan_not_generatable',
            statusCode: 422,
            context: ['status' => $status],
        );
    }
}
