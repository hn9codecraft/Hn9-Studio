<?php

declare(strict_types=1);

namespace App\Story\Exceptions;

use App\Story\Enums\StoryVideoErrorCode;

class StoryVideoEngineException extends StoryException
{
    public static function capabilityNotAvailable(string $detail = 'No eligible video provider can satisfy this request.'): self
    {
        return new self(
            message: $detail,
            errorCode: StoryVideoErrorCode::CapabilityNotAvailable->value,
            statusCode: 422,
        );
    }

    public static function invalidInput(string $detail): self
    {
        return new self(
            message: $detail,
            errorCode: StoryVideoErrorCode::InvalidInput->value,
            statusCode: 422,
        );
    }

    public static function provider(StoryVideoErrorCode $code, string $detail): self
    {
        return new self(
            message: $detail,
            errorCode: $code->value,
            statusCode: 422,
        );
    }

    public static function generationNotEnabled(): self
    {
        return new self(
            message: 'Video creation is not connected yet. An administrator needs to connect a video service before videos can be made.',
            errorCode: StoryVideoErrorCode::GenerationNotEnabled->value,
            statusCode: 501,
        );
    }
}
