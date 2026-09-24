<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Bytes already written to the images disk, plus the metadata the provider actually returned.
 */
final readonly class StoredImage
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $disk,
        public string $path,
        public string $mimeType,
        public string $extension,
        public int $size,
        public ?string $checksum,
        public ?int $width,
        public ?int $height,
        public array $meta = [],
    ) {}
}
