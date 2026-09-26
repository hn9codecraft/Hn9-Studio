<?php

declare(strict_types=1);

namespace App\Story\Video;

use App\Story\Enums\StoryVideoCapability;

final readonly class StoryVideoCompatibilityResult
{
    /**
     * @param  list<string>  $issues
     * @param  array<string, mixed>  $checks
     */
    public function __construct(
        public StoryVideoCapability $capability,
        public bool $compatible,
        public array $issues = [],
        public array $checks = [],
        public ?StoryVideoRoutingDecision $routing = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'capability' => $this->capability->value,
            'compatible' => $this->compatible,
            'issues' => $this->issues,
            'checks' => $this->checks,
            'routing' => $this->routing?->toArray(),
        ];
    }
}
