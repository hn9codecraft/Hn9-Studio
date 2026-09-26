<?php

declare(strict_types=1);

namespace App\Story\Video;

use App\Story\Enums\StoryVideoAsyncMode;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoInputType;

final readonly class StoryVideoInput
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public StoryVideoInputType $type,
        public ?string $assetId = null,
        public array $metadata = [],
        public ?string $role = null,
        public int $order = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'asset_id' => $this->assetId,
            'role' => $this->role,
            'order' => $this->order,
            'metadata' => $this->metadata,
        ];
    }
}
