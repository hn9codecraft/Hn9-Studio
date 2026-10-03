<?php

declare(strict_types=1);

namespace App\Story\Media;

final readonly class StoryMediaFile
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $mime,
        public int $size,
        public string $checksum,
        public float $durationSeconds,
        public int $width = 0,
        public int $height = 0,
        public bool $hasVideo = false,
        public bool $hasAudio = false,
    ) {}

    /**
     * @return array{disk: string, path: string, mime: string, size: int, checksum: string, duration_seconds: float}
     */
    public function toStorage(): array
    {
        return [
            'disk' => $this->disk,
            'path' => $this->path,
            'mime' => $this->mime,
            'size' => $this->size,
            'checksum' => $this->checksum,
            'duration_seconds' => round($this->durationSeconds, 2),
        ];
    }
}
