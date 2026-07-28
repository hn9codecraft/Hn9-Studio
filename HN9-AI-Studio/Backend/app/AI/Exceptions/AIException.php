<?php

declare(strict_types=1);

namespace App\AI\Exceptions;

use App\Exceptions\DomainException;
use Throwable;

/**
 * Base class for every AI-provider-subsystem failure. Extends the Sprint 5.2
 * {@see DomainException} so the existing API error envelope (error code, HTTP
 * status, context) renders these uniformly.
 */
class AIException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'An AI provider error occurred.',
        string $errorCode = 'ai_error',
        int $statusCode = 422,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $statusCode, $context, $previous);
    }
}
