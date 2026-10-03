<?php

declare(strict_types=1);

namespace App\Story\Video\Adapters;

use App\Story\Enums\StoryVideoErrorCode;

/**
 * One provider task snapshot translated into engine terms.
 */
final readonly class StoryVideoTaskState
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /**
     * @param  array<string, int|float|string>  $usage
     */
    public function __construct(
        public string $state,
        public ?string $videoUrl = null,
        public ?StoryVideoErrorCode $errorCode = null,
        public ?string $errorMessage = null,
        public array $usage = [],
        public ?float $progress = null,
    ) {}

    public function terminal(): bool
    {
        return in_array($this->state, [self::SUCCEEDED, self::FAILED], true);
    }
}
