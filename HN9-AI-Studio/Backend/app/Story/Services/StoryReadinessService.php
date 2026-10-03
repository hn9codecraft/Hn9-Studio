<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\AI\Contracts\ProviderManagerInterface;
use App\AI\Support\Capability;
use App\Story\Enums\StoryAudioRole;
use App\Story\Enums\StoryVideoCapability;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Which Creative Studio generation features can actually run, from configuration alone.
 * A provider counts only when it is enabled, serves the capability and has credentials.
 * No provider is called, and no credential value leaves this class.
 */
final readonly class StoryReadinessService
{
    public function __construct(
        private ProviderManagerInterface $providers,
        private StoryVideoDispatchService $dispatch,
        private Config $config,
    ) {}

    /**
     * @return array{
     *     story_planning: bool,
     *     reference_images: bool,
     *     video: array{text: bool, image: bool, reference: bool, edit: bool, extend: bool},
     *     sound: array{roles: list<string>}
     * }
     */
    public function connections(): array
    {
        return [
            'story_planning' => $this->aiConnected(Capability::Text),
            'reference_images' => $this->aiConnected(Capability::Image),
            'video' => [
                'text' => $this->dispatch->liveSupports(StoryVideoCapability::TextToVideo),
                'image' => $this->dispatch->liveSupports(StoryVideoCapability::ImageToVideo),
                'reference' => $this->dispatch->liveSupports(StoryVideoCapability::ReferenceToVideo),
                'edit' => $this->dispatch->liveSupports(StoryVideoCapability::VideoEdit),
                'extend' => $this->dispatch->liveSupports(StoryVideoCapability::VideoExtend),
            ],
            'sound' => [
                'roles' => $this->dispatch->liveSupports(StoryVideoCapability::Audio)
                    ? array_values($this->dispatch->liveAudioRoles() ?: StoryAudioRole::values())
                    : [],
            ],
        ];
    }

    private function aiConnected(Capability $capability): bool
    {
        foreach ($this->providers->forCapability($capability) as $key) {
            $apiKey = $this->config->get("ai.providers.{$key}.api_key");
            if (is_string($apiKey) && trim($apiKey) !== '') {
                return true;
            }
        }

        return false;
    }
}
