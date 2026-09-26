<?php

declare(strict_types=1);

namespace App\Story\Video;

use App\Story\Enums\StoryVideoCapability;

/**
 * Provider-neutral model metadata. Model keys come from configuration/adapters.
 */
final readonly class StoryVideoModelSpec
{
    /**
     * @param  list<StoryVideoCapability>  $capabilities
     * @param  list<int>  $durations
     * @param  list<string>  $aspectRatios
     * @param  list<string>  $resolutions
     * @param  list<string>  $inputTypes
     * @param  array<string, mixed>  $limits
     */
    public function __construct(
        public string $providerKey,
        public string $modelKey,
        public string $displayName,
        public array $capabilities = [],
        public bool $enabled = true,
        public int $priority = 100,
        public array $durations = [],
        public array $aspectRatios = [],
        public array $resolutions = [],
        public array $inputTypes = [],
        public bool $audioSupported = false,
        public array $limits = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'provider' => $this->providerKey,
            'model' => $this->modelKey,
            'label' => $this->displayName,
            'enabled' => $this->enabled,
            'priority' => $this->priority,
            'capabilities' => array_map(
                static fn (StoryVideoCapability $capability): string => $capability->value,
                $this->capabilities,
            ),
            'supported_durations' => $this->durations,
            'supported_aspect_ratios' => $this->aspectRatios,
            'supported_resolutions' => $this->resolutions,
            'supported_input_types' => $this->inputTypes,
            'audio_supported' => $this->audioSupported,
            'limits' => $this->limits,
        ];
    }
}
