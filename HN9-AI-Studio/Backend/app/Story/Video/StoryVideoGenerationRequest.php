<?php

declare(strict_types=1);

namespace App\Story\Video;

use App\Story\Enums\StoryVideoCapability;

/**
 * Provider-neutral generation request. No vendor-specific fields in core.
 */
final readonly class StoryVideoGenerationRequest
{
    /**
     * @param  list<StoryVideoInput>  $inputs
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public StoryVideoCapability $capability,
        public ?string $workspaceUuid = null,
        public ?string $reelUuid = null,
        public ?string $sceneUuid = null,
        public ?string $prompt = null,
        public ?string $negativePrompt = null,
        public ?int $durationSeconds = null,
        public ?string $aspectRatio = null,
        public ?string $resolution = null,
        public bool $audioRequested = false,
        public array $inputs = [],
        public ?string $preferredProvider = null,
        public ?string $preferredModel = null,
        public ?string $idempotencyKey = null,
        public array $metadata = [],
        public array $extensions = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'capability' => $this->capability->value,
            'workspace_id' => $this->workspaceUuid,
            'reel_id' => $this->reelUuid,
            'scene_id' => $this->sceneUuid,
            'prompt' => $this->prompt,
            'negative_prompt' => $this->negativePrompt,
            'duration_seconds' => $this->durationSeconds,
            'aspect_ratio' => $this->aspectRatio,
            'resolution' => $this->resolution,
            'audio_requested' => $this->audioRequested,
            'inputs' => array_map(
                static fn (StoryVideoInput $input): array => $input->toArray(),
                $this->inputs,
            ),
            'preferred_provider' => $this->preferredProvider,
            'preferred_model' => $this->preferredModel,
            'idempotency_key' => $this->idempotencyKey,
            'metadata' => $this->metadata,
            'extensions' => $this->extensions,
        ];
    }
}
