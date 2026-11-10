<?php

declare(strict_types=1);

namespace App\Story\Support;

use App\Story\Video\StoryVideoInput;

/**
 * The exact video request a preview shows and a later Generate must match.
 */
final readonly class StoryComposedPrompt
{
    /**
     * @param  list<string>  $trimmed
     * @param  list<StoryVideoInput>  $inputs
     * @param  list<array<string, mixed>>  $locks
     * @param  list<string>  $warnings
     * @param  list<array<string, mixed>>  $provenanceLocks
     * @param  list<string>  $referencesSent
     */
    public function __construct(
        public string $base,
        public string $lockBlock,
        public string $final,
        public ?int $limit,
        public array $trimmed,
        public array $inputs,
        public array $locks,
        public array $warnings,
        public bool $edited,
        public bool $editStale,
        public string $automaticBase,
        public array $provenanceLocks,
        public array $referencesSent,
    ) {}
}
