<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * Image generation failed before a studio image or stored file was committed.
 */
class ImageGenerationException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'Image generation failed.',
        string $errorCode = 'image_generation_failed',
        int $statusCode = 422,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $statusCode, $context, $previous);
    }

    public static function unsupported(?string $provider = null, ?string $model = null): self
    {
        return new self(
            message: 'The selected provider/model does not support image generation.',
            errorCode: 'image_generation_unsupported',
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
            message: 'An image prompt is required.',
            errorCode: 'image_generation_prompt_required',
            statusCode: 422,
        );
    }

    public static function malformed(): self
    {
        return new self(
            message: 'The provider response did not contain a usable image.',
            errorCode: 'image_generation_malformed_response',
            statusCode: 502,
        );
    }

    public static function storageFailed(?Throwable $previous = null): self
    {
        return new self(
            message: 'The generated image could not be stored.',
            errorCode: 'image_storage_failed',
            statusCode: 502,
            previous: $previous,
        );
    }
}
