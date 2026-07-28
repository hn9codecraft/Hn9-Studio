<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\WorkflowStatus;
use Throwable;

/**
 * Raised for invalid workflow-run state operations in the domain layer
 * (illegal status transition, acting on a finished run).
 *
 * Concerns the workflow *record's* lifecycle only — the pipeline execution
 * engine is a later sprint.
 */
class WorkflowException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'A workflow error occurred.',
        string $errorCode = 'workflow_error',
        int $statusCode = 409,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $statusCode, $context, $previous);
    }

    public static function invalidTransition(WorkflowStatus $from, WorkflowStatus $to): self
    {
        return new self(
            message: "Cannot transition workflow run from [{$from->value}] to [{$to->value}].",
            errorCode: 'workflow_invalid_transition',
            statusCode: 409,
            context: ['from' => $from->value, 'to' => $to->value],
        );
    }

    public static function alreadyFinished(WorkflowStatus $status): self
    {
        return new self(
            message: "Workflow run is already finished [{$status->value}].",
            errorCode: 'workflow_finished',
            statusCode: 409,
            context: ['status' => $status->value],
        );
    }
}
