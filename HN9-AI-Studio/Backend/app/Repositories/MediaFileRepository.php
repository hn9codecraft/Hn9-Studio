<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\MediaFile;
use App\Repositories\Contracts\MediaFileRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends BaseRepository<MediaFile>
 */
class MediaFileRepository extends BaseRepository implements MediaFileRepositoryInterface
{
    /**
     * @return Builder<MediaFile>
     */
    protected function query(): Builder
    {
        return MediaFile::query();
    }

    protected function filterable(): array
    {
        return ['collection', 'mime_type', 'disk'];
    }

    public function forOwner(Model $owner): Collection
    {
        return $this->query()
            ->where('mediable_type', $owner->getMorphClass())
            ->where('mediable_id', $owner->getKey())
            ->latest('id')
            ->get();
    }

    public function findByChecksum(string $checksum): ?MediaFile
    {
        return $this->query()->where('checksum', $checksum)->first();
    }
}
