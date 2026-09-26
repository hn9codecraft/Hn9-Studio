<?php

declare(strict_types=1);

namespace App\Story\Video;

use App\Story\Enums\StoryVideoCapability;

final readonly class StoryCapabilityRoute
{
    /**
     * @param  list<int>  $durations
     * @param  list<string>  $aspectRatios
     * @param  list<string>  $resolutions
     * @param  list<string>  $inputTypes
     * @param  list<string>  $adapterKeys
     */
    public function __construct(
        public StoryVideoCapability $capability,
        public bool $available,
        public array $durations = [],
        public ?int $minDurationSeconds = null,
        public ?int $maxDurationSeconds = null,
        public array $aspectRatios = [],
        public array $resolutions = [],
        public array $inputTypes = [],
        public bool $audioSupported = false,
        public bool $asyncSupported = true,
        public bool $pollingSupported = false,
        public bool $webhookSupported = false,
        public bool $downloadSupported = false,
        public array $adapterKeys = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'capability' => $this->capability->value,
            'label' => $this->capability->label(),
            'available' => $this->available,
            'supported' => $this->adapterKeys !== [],
            'min_duration_seconds' => $this->minDurationSeconds,
            'max_duration_seconds' => $this->maxDurationSeconds,
            'supported_durations' => $this->durations,
            'supported_aspect_ratios' => $this->aspectRatios,
            'supported_resolutions' => $this->resolutions,
            'supported_input_types' => $this->inputTypes,
            'audio_supported' => $this->audioSupported,
            'async_supported' => $this->asyncSupported,
            'polling_supported' => $this->pollingSupported,
            'webhook_supported' => $this->webhookSupported,
            'download_supported' => $this->downloadSupported,
            'adapters' => $this->adapterKeys,
        ];
    }
}
