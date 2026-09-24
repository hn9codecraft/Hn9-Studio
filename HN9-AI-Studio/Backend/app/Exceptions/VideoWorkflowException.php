<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

class VideoWorkflowException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'This video cannot complete that review action in its current status.',
        string $errorCode = 'video_workflow_invalid',
        int $statusCode = 409,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $statusCode, $context, $previous);
    }

    public static function invalidTransition(string $videoUuid, string $action, string $status): self
    {
        return new self(
            message: "This video cannot be {$action} while its status is [{$status}].",
            errorCode: 'video_workflow_invalid_transition',
            statusCode: 409,
            context: [
                'video' => $videoUuid,
                'action' => $action,
                'status' => $status,
            ],
        );
    }

    public static function editLocked(string $videoUuid, string $status): self
    {
        return new self(
            message: 'This video is locked and cannot be edited in its current status.',
            errorCode: 'video_workflow_edit_locked',
            statusCode: 409,
            context: [
                'video' => $videoUuid,
                'status' => $status,
            ],
        );
    }

    public static function projectNotEditable(string $projectUuid): self
    {
        return new self(
            message: 'This project cannot accept review actions in its current status.',
            errorCode: 'video_workflow_project_not_editable',
            statusCode: 409,
            context: ['project' => $projectUuid],
        );
    }

    public static function commentRequired(): self
    {
        return new self(
            message: 'A rework comment is required so the creator can see what to change.',
            errorCode: 'video_workflow_comment_required',
            statusCode: 422,
        );
    }
}
