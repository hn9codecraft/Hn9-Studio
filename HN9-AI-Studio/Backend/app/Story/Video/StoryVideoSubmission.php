<?php

declare(strict_types=1);

namespace App\Story\Video;

use App\Story\Enums\StoryVideoJobStatus;

/**
 * Normalized result of a provider submit. No vendor payload.
 */
final readonly class StoryVideoSubmission
{
    public function __construct(
        public string $operationId,
        public StoryVideoJobStatus $status,
        public ?string $modelKey = null,
        public bool $done = false,
        public ?string $downloadUri = null,
    ) {}
}
