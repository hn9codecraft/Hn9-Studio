<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Contracts\Storage\StorageInterface;
use App\Support\StorageHelper;
use Illuminate\Support\Facades\Storage;

/**
 * Filesystem-backed implementation of {@see StorageInterface}. Resolves the
 * logical HN9 disk names to real disks and delegates to Laravel's filesystem,
 * so the physical backend (local, S3, …) is transparent to callers.
 */
final class FilesystemStorage implements StorageInterface
{
    public function put(string $logicalDisk, string $path, string $contents): string
    {
        Storage::disk(StorageHelper::disk($logicalDisk))->put($path, $contents);

        return $path;
    }

    public function get(string $logicalDisk, string $path): ?string
    {
        return Storage::disk(StorageHelper::disk($logicalDisk))->get($path);
    }

    public function exists(string $logicalDisk, string $path): bool
    {
        return Storage::disk(StorageHelper::disk($logicalDisk))->exists($path);
    }

    public function delete(string $logicalDisk, string $path): bool
    {
        return Storage::disk(StorageHelper::disk($logicalDisk))->delete($path);
    }

    public function url(string $logicalDisk, string $path): ?string
    {
        try {
            return Storage::disk(StorageHelper::disk($logicalDisk))->url($path);
        } catch (\Throwable) {
            return null;
        }
    }
}
