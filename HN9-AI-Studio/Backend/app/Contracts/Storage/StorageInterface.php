<?php

declare(strict_types=1);

namespace App\Contracts\Storage;

use App\Support\StorageHelper;

/**
 * Abstraction over the physical storage backend for HN9 media/output. Services
 * depend on this contract rather than the Storage facade so the backing disk
 * (local, S3, …) can change without touching call sites.
 *
 * Logical disk names ("images", "videos", …) map to real disks via
 * config/hn9.php — see {@see StorageHelper}.
 */
interface StorageInterface
{
    /**
     * Store raw contents on a logical disk and return the stored path.
     */
    public function put(string $logicalDisk, string $path, string $contents): string;

    /**
     * Read the contents of a stored file, or null when absent.
     */
    public function get(string $logicalDisk, string $path): ?string;

    /**
     * Whether a file exists on the logical disk.
     */
    public function exists(string $logicalDisk, string $path): bool;

    /**
     * Delete a file from the logical disk.
     */
    public function delete(string $logicalDisk, string $path): bool;

    /**
     * Resolve a public URL for a file, or null for private disks.
     */
    public function url(string $logicalDisk, string $path): ?string;
}
