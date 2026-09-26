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

    public static function generationNotEnabled(): self
    {
        return new self(
            message: 'Story video provider submission is reserved for a later sprint. The engine accepts routing and job contracts only.',
            errorCode: StoryVideoErrorCode::GenerationNotEnabled->value,
            statusCode: 501,
        );
    }
}
