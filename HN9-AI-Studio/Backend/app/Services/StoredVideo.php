<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Bytes already written to the videos disk.
 */
final readonly class StoredVideo
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $mimeType,
        public string $extension,
        public int $size,
        public ?string $checksum,
    ) {}
}
