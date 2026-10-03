<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

use App\Exceptions\DomainException;
use Throwable;

class StoryException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        string $errorCode = 'story_error',
        int $statusCode = 400,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            errorCode: $errorCode,
            statusCode: $statusCode,
            context: $context,
            previous: $previous,
        );
    }

    public static function notFound(string $resource = 'Resource'): self
    {
        return new self(
            message: "{$resource} not found.",
            errorCode: 'story_not_found',
            statusCode: 404,
        );
    }
}
