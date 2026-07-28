<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * Raised for invalid generation-request operations in the domain layer — e.g.
 * a request targeting a non-editable project, or an unsupported deliverable.
 *
 * This concerns the *request* (intent to generate) only. No generation is
 * performed in this sprint; the execution pipeline arrives later.
 */
class GenerationException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'A generation request error occurred.',
        string $errorCode = 'generation_error',
        int $statusCode = 422,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $statusCode, $context, $previous);
    }

    public static function projectNotEditable(string $projectUuid): self
    {
        return new self(
            message: 'This project cannot accept new generation requests in its current status.',
            errorCode: 'generation_project_not_editable',
            statusCode: 409,
            context: ['project' => $projectUuid],
        );
    }

    public static function unsupportedDeliverable(string $deliverable): self
    {
        return new self(
            message: "Unsupported deliverable type [{$deliverable}].",
            errorCode: 'generation_unsupported_deliverable',
            statusCode: 422,
            context: ['deliverable' => $deliverable],
        );
    }
}
