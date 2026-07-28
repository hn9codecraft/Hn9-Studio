<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for every HN9 domain-layer failure. Carries an optional
 * machine-readable error code, HTTP status hint and structured context so the
 * API layer can render a consistent JSON envelope without leaking internals.
 *
 * All domain-specific exceptions extend this class, allowing a single catch
 * site (`catch (DomainException $e)`) to handle the whole family.
 */
class DomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'A domain error occurred.',
        protected string $errorCode = 'domain_error',
        protected int $statusCode = 422,
        protected array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Machine-readable error code (e.g. "project_not_editable").
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Suggested HTTP status code for the API layer.
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Additional structured context safe to expose to the client.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
