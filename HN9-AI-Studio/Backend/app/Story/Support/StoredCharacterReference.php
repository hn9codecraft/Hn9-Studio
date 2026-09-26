<?php

declare(strict_types=1);

namespace App\Story\Support;

/**
 * Bytes written to private storage for a character reference.
 */
final readonly class StoredCharacterReference
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $mimeType,
        public string $extension,
        public int $size,
        public ?string $checksum,
        public ?int $width,
        public ?int $height,
        public ?string $originalFilename = null,
    ) {}
}
