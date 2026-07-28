<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\MediaFile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends RepositoryInterface<MediaFile>
 */
interface MediaFileRepositoryInterface extends RepositoryInterface
{
    /**
     * All media files attached to the given owner model.
     *
     * @return Collection<int, MediaFile>
     */
    public function forOwner(Model $owner): Collection;

    /**
     * Find a media file by content checksum (dedupe/integrity), or null.
     */
    public function findByChecksum(string $checksum): ?MediaFile;
}
