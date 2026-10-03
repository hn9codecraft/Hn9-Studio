<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

class StoryPlanApprovalException extends StoryException
{
    public static function unfinished(): self
    {
        return new self(
            message: 'This story version is still being written. Approve it once it is finished.',
            errorCode: 'story_plan_version_unfinished',
            statusCode: 422,
        );
    }

    public static function failedVersion(): self
    {
        return new self(
            message: 'This story version could not be written, so it cannot be approved. Plan the story again.',
            errorCode: 'story_plan_version_failed',
            statusCode: 422,
        );
    }

    public static function notLatest(): self
    {
        return new self(
            message: 'A newer version of this story exists. Review and approve the latest version instead.',
            errorCode: 'story_plan_version_not_latest',
            statusCode: 409,
        );
    }

    public static function invalidPlan(): self
    {
        return new self(
            message: 'Some scenes in this story plan are incomplete, so it cannot be prepared for production. Plan the story again.',
            errorCode: 'story_approval_invalid_plan',
            statusCode: 422,
        );
    }

    public static function failed(): self
    {
        return new self(
            message: 'We couldn\'t prepare this story for production. Nothing was changed.',
            errorCode: 'story_approval_failed',
            statusCode: 500,
        );
    }
}
