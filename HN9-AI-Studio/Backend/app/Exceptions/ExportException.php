<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

class ExportException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'Project export failed.',
        string $errorCode = 'export_failed',
        int $statusCode = 422,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $statusCode, $context, $previous);
    }

    /**
     * @param  list<array{code: string, message: string}>  $issues
     */
    public static function notReady(array $issues): self
    {
        return new self(
            message: 'This project is not ready for export.',
            errorCode: 'project_not_ready_for_export',
            statusCode: 422,
            context: ['issues' => $issues],
        );
    }

    public static function notFinalized(string $status): self
    {
        return new self(
            message: 'Finalize the project before exporting.',
            errorCode: 'project_not_finalized',
            statusCode: 409,
            context: ['status' => $status],
        );
    }

    public static function projectArchived(): self
    {
        return new self(
            message: 'Archived projects cannot be finalized or exported.',
            errorCode: 'export_project_archived',
            statusCode: 409,
        );
    }

    public static function inProgress(string $exportUuid): self
    {
        return new self(
            message: 'An export is already in progress for this project.',
            errorCode: 'export_in_progress',
            statusCode: 409,
            context: ['export' => $exportUuid],
        );
    }

    public static function notReadyToDownload(): self
    {
        return new self(
            message: 'This export is not ready to download.',
            errorCode: 'export_not_ready',
            statusCode: 409,
        );
    }

    public static function missingPackage(): self
    {
        return new self(
            message: 'The export package is missing from storage.',
            errorCode: 'export_package_missing',
            statusCode: 409,
        );
    }

    public static function packageFailed(?Throwable $previous = null): self
    {
        return new self(
            message: 'The export package could not be created.',
            errorCode: 'export_package_failed',
            statusCode: 502,
            previous: $previous,
        );
    }

    public static function assetFileMissing(string $kind, string $assetUuid): self
    {
        return new self(
            message: "An approved {$kind} is missing its stored file.",
            errorCode: 'export_asset_file_missing',
            statusCode: 422,
            context: ['kind' => $kind, 'asset' => $assetUuid],
        );
    }

    public static function invalidRequest(): self
    {
        return new self(
            message: 'A project is required to create an export. CSV and other formats are not supported.',
            errorCode: 'export_invalid_request',
            statusCode: 422,
        );
    }
}
