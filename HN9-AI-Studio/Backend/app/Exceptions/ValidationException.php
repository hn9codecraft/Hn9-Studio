<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * Domain-level validation failure — a business rule was violated *after* HTTP
 * form-request validation passed (e.g. an illegal status transition, or a
 * uniqueness rule enforced by a service).
 *
 * Distinct from {@see \Illuminate\Validation\ValidationException}, which the
 * HTTP layer throws for request input. This one carries field => messages in
 * the same shape so the API layer can present them uniformly.
 */
class ValidationException extends DomainException
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'The given data was invalid.',
        protected array $errors = [],
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 'validation_failed', 422, $context, $previous);
    }

    /**
     * Field-keyed validation messages.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Build an exception for a single field.
     */
    public static function forField(string $field, string $message): self
    {
        return new self(
            message: $message,
            errors: [$field => [$message]],
        );
    }
}
