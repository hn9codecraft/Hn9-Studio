<?php

declare(strict_types=1);

namespace App\Story\Video;

/**
 * Normalized provider output contract. No download occurs in M11.6.
 */
final readonly class StoryVideoGenerationOutput
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ?string $mediaReference = null,
        public ?string $mimeType = null,
        public ?string $providerOutputId = null,
        public string $downloadStrategy = 'none',
        public ?string $checksum = null,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'media_reference' => $this->mediaReference,
            'mime_type' => $this->mimeType,
            'provider_output_id' => $this->providerOutputId,
            'download_strategy' => $this->downloadStrategy,
            'checksum' => $this->checksum,
            'metadata' => $this->metadata,
        ];
    }
}
