<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

use App\Story\Models\StoryVideoGenerationJob;

final class StoryUnitRetryException extends StoryException
{
    /**
     * @param  array{code: string, reason: string, retryable: bool, hint: string}  $failure
     */
    public static function failed(StoryVideoGenerationJob $job, array $failure, bool $retryAvailable): self
    {
        return new self(
            message: 'This attempt failed. Use Retry to start a new one.',
            errorCode: 'story_unit_generation_failed',
            statusCode: 409,
            context: [
                'job_uuid' => $job->uuid,
                'failure' => $failure,
                'retry_available' => $retryAvailable,
            ],
        );
    }

    public static function notAllowed(string $status): self
    {
        return new self(
            message: 'This unit cannot be retried while its latest attempt is '.$status.'.',
            errorCode: 'story_unit_retry_not_allowed',
            statusCode: 409,
            context: ['status' => $status],
        );
    }

    public static function notRetryable(string $reason): self
    {
        return new self(
            message: 'Change the prompt and generate again.',
            errorCode: 'story_unit_retry_not_retryable',
            statusCode: 422,
            context: ['reason' => $reason],
        );
    }

    public static function limit(int $max): self
    {
        return new self(
            message: 'This unit has used all of its retries.',
            errorCode: 'story_unit_retry_limit',
            statusCode: 422,
            context: ['max_retries' => $max],
        );
    }
}
