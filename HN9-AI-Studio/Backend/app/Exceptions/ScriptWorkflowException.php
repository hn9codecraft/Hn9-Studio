<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * Invalid script review/approval transitions and locked-state edits.
 */
class ScriptWorkflowException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'This script cannot complete that review action in its current status.',
        string $errorCode = 'script_workflow_invalid',
        int $statusCode = 409,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $statusCode, $context, $previous);
    }

    public static function invalidTransition(string $scriptUuid, string $action, string $status): self
    {
        return new self(
            message: "This script cannot be {$action} while its status is [{$status}].",
            errorCode: 'script_workflow_invalid_transition',
            statusCode: 409,
            context: [
                'script' => $scriptUuid,
                'action' => $action,
                'status' => $status,
            ],
        );
    }

    public static function editLocked(string $scriptUuid, string $status): self
    {
        return new self(
            message: 'This script is locked and cannot be edited in its current status.',
            errorCode: 'script_workflow_edit_locked',
            statusCode: 409,
            context: [
                'script' => $scriptUuid,
                'status' => $status,
            ],
        );
    }

    public static function statusNotAssignable(string $scriptUuid, string $status): self
    {
        return new self(
            message: 'Script review status can only change through the review workflow.',
            errorCode: 'script_workflow_status_not_assignable',
            statusCode: 422,
            context: [
                'script' => $scriptUuid,
                'status' => $status,
            ],
        );
    }

    public static function projectNotEditable(string $projectUuid): self
    {
        return new self(
            message: 'This project cannot accept review actions in its current status.',
            errorCode: 'script_workflow_project_not_editable',
            statusCode: 409,
            context: ['project' => $projectUuid],
        );
    }

    public static function commentRequired(): self
    {
        return new self(
            message: 'A rework comment is required so the creator can see what to change.',
            errorCode: 'script_workflow_comment_required',
            statusCode: 422,
            context: [],
        );
    }
}
