<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

class VideoGenerationException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'Video generation failed.',
        string $errorCode = 'video_generation_failed',
        int $statusCode = 422,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $statusCode, $context, $previous);
    }

    public static function unsupported(?string $provider = null, ?string $model = null): self
    {
        return new self(
            message: 'No configured provider supports video generation, or the selected model is not a video model.',
            errorCode: 'video_generation_unsupported',
            statusCode: 422,
            context: array_filter([
                'provider' => $provider,
                'model' => $model,
            ], static fn (mixed $value): bool => is_string($value) && $value !== ''),
        );
    }

    public static function promptRequired(): self
    {
        return new self(
            message: 'A video prompt is required.',
            errorCode: 'video_generation_invalid_request',
            statusCode: 422,
        );
    }

    public static function invalidOption(string $field, string $message): self
    {
        return new self(
            message: $message,
            errorCode: 'video_generation_invalid_request',
            statusCode: 422,
            context: ['field' => $field],
        );
    }

    public static function sourceImageUnavailable(string $imageUuid): self
    {
        return new self(
            message: 'The source image is not available for image-to-video generation.',
            errorCode: 'video_generation_invalid_request',
            statusCode: 422,
            context: ['image' => $imageUuid],
        );
    }

    public static function projectNotEditable(string $projectUuid): self
    {
        return new self(
            message: 'This project cannot accept video generation in its current status.',
            errorCode: 'video_generation_project_not_editable',
            statusCode: 409,
            context: ['project' => $projectUuid],
        );
    }

    public static function notReady(): self
    {
        return new self(
            message: 'The video is not ready to play yet.',
            errorCode: 'video_asset_not_ready',
            statusCode: 409,
        );
    }

    public static function timeout(string $videoUuid): self
    {
        return new self(
            message: 'The video provider did not finish before the timeout.',
            errorCode: 'video_generation_timeout',
            statusCode: 504,
            context: ['video' => $videoUuid],
        );
    }

    public static function storageFailed(?Throwable $previous = null): self
    {
        return new self(
            message: 'The generated video could not be stored.',
            errorCode: 'video_storage_failed',
            statusCode: 502,
            previous: $previous,
        );
    }

    public static function retrievalFailed(?string $detail = null): self
    {
        return new self(
            message: $detail ?: 'The completed video could not be retrieved from the provider.',
            errorCode: 'video_generation_failed',
            statusCode: 502,
        );
    }

    public static function inProgress(string $videoUuid): self
    {
        return new self(
            message: 'A video generation request for this prompt is already in progress.',
            errorCode: 'video_generation_in_progress',
            statusCode: 409,
            context: ['video' => $videoUuid],
        );
    }
}
