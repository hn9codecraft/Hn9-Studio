<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Storage helpers. Resolves the logical HN9 disks declared in config/hn9.php
 * and builds tidy, collision-resistant storage paths. Call sites reference
 * logical names ("images", "videos") so the physical disk can be repointed
 * without touching them.
 */
final class StorageHelper
{
    /**
     * Resolve a logical HN9 disk name to its configured filesystem disk.
     * Falls back to the given default when the logical name is unknown.
     */
    public static function disk(string $logical, string $default = 'local'): string
    {
        /** @var array<string, string> $map */
        $map = config('hn9.disks', []);

        return $map[$logical] ?? $default;
    }

    /**
     * Build a namespaced storage path, e.g. projects/{uuid}/images/{file}.
     * Segments are individually slugified/normalised and empties dropped.
     */
    public static function path(string ...$segments): string
    {
        $clean = array_map(
            static fn (string $segment): string => trim($segment, '/'),
            array_filter($segments, static fn (string $segment): bool => $segment !== ''),
        );

        return implode('/', $clean);
    }

    /**
     * Generate a unique, safe filename preserving the original extension.
     */
    public static function uniqueFilename(string $originalName): string
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $name = Uuid::generate();

        return $extension !== '' ? "{$name}.".Str::lower($extension) : $name;
    }
}
