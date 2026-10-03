<?php

declare(strict_types=1);

namespace App\Story\Video;

use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoInputType;

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
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $capability = StoryVideoCapability::from((string) ($payload['capability'] ?? StoryVideoCapability::TextToVideo->value));
        $inputs = [];
        foreach ($payload['inputs'] ?? [] as $index => $row) {
            if (! is_array($row) || ! isset($row['type'])) {
                continue;
            }
            $type = StoryVideoInputType::tryFrom((string) $row['type']);
            if ($type === null) {
                continue;
            }
            $inputs[] = new StoryVideoInput(
                type: $type,
                assetId: isset($row['asset_id']) ? (string) $row['asset_id'] : null,
                metadata: is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
                role: isset($row['role']) ? (string) $row['role'] : null,
                order: (int) ($row['order'] ?? $index),
            );
        }

        return new self(
            capability: $capability,
            workspaceUuid: isset($payload['workspace_id']) ? (string) $payload['workspace_id'] : null,
            reelUuid: isset($payload['reel_id']) ? (string) $payload['reel_id'] : null,
            sceneUuid: isset($payload['scene_id']) ? (string) $payload['scene_id'] : null,
            prompt: isset($payload['prompt']) ? (string) $payload['prompt'] : null,
            negativePrompt: isset($payload['negative_prompt']) ? (string) $payload['negative_prompt'] : null,
            durationSeconds: isset($payload['duration_seconds']) ? (int) $payload['duration_seconds'] : null,
            aspectRatio: isset($payload['aspect_ratio']) ? (string) $payload['aspect_ratio'] : null,
            resolution: isset($payload['resolution']) ? (string) $payload['resolution'] : null,
            audioRequested: (bool) ($payload['audio_requested'] ?? false),
            inputs: $inputs,
            preferredProvider: isset($payload['preferred_provider']) ? (string) $payload['preferred_provider'] : null,
            preferredModel: isset($payload['preferred_model']) ? (string) $payload['preferred_model'] : null,
            idempotencyKey: isset($payload['idempotency_key']) ? (string) $payload['idempotency_key'] : null,
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            extensions: is_array($payload['extensions'] ?? null) ? $payload['extensions'] : [],
        );
    }

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
