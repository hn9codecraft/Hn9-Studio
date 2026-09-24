<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * Invalid image review transitions and locked-state edits.
 */
class ImageWorkflowException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'This image cannot complete that review action in its current status.',
        string $errorCode = 'image_workflow_invalid',
        int $statusCode = 409,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $statusCode, $context, $previous);
    }

    public static function invalidTransition(string $imageUuid, string $action, string $status): self
    {
        return new self(
            message: "This image cannot be {$action} while its status is [{$status}].",
            errorCode: 'image_workflow_invalid_transition',
            statusCode: 409,
            context: [
                'image' => $imageUuid,
                'action' => $action,
                'status' => $status,
            ],
        );
    }

    public static function editLocked(string $imageUuid, string $status): self
    {
        return new self(
            message: 'This image is locked and cannot be edited in its current status.',
            errorCode: 'image_workflow_edit_locked',
            statusCode: 409,
            context: [
                'image' => $imageUuid,
                'status' => $status,
            ],
        );
    }

    public static function statusNotAssignable(string $imageUuid, string $status): self
    {
        return new self(
            message: 'Image review status can only change through the review workflow.',
            errorCode: 'image_workflow_status_not_assignable',
            statusCode: 422,
            context: [
                'image' => $imageUuid,
                'status' => $status,
            ],
        );
    }

    public static function projectNotEditable(string $projectUuid): self
    {
        return new self(
            message: 'This project cannot accept review actions in its current status.',
            errorCode: 'image_workflow_project_not_editable',
            statusCode: 409,
            context: ['project' => $projectUuid],
        );
    }

    public static function commentRequired(): self
    {
        return new self(
            message: 'A rework comment is required so the creator can see what to change.',
            errorCode: 'image_workflow_comment_required',
            statusCode: 422,
            context: [],
        );
    }
}
