<?php

declare(strict_types=1);

namespace App\Story\Video;

use App\Story\Enums\StoryVideoAsyncMode;
use App\Story\Enums\StoryVideoCapability;

final readonly class StoryVideoRoutingDecision
{
    /**
     * @param  list<string>  $fallbackProviders
     * @param  array<string, mixed>  $reasons
     */
    public function __construct(
        public StoryVideoCapability $capability,
        public bool $matched,
        public ?string $providerKey = null,
        public ?string $modelKey = null,
        public ?int $priority = null,
        public ?StoryVideoAsyncMode $asyncMode = null,
        public array $fallbackProviders = [],
        public array $reasons = [],
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'capability' => $this->capability->value,
            'matched' => $this->matched,
            'provider' => $this->providerKey,
            'model' => $this->modelKey,
            'priority' => $this->priority,
            'async_mode' => $this->asyncMode?->value,
            'fallback_providers' => $this->fallbackProviders,
            'reasons' => $this->reasons,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
        ];
    }
}
