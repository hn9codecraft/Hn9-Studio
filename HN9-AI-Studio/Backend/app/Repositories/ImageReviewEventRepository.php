<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Image;
use App\Models\ImageReviewEvent;
use App\Repositories\Contracts\ImageReviewEventRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<ImageReviewEvent>
 */
class ImageReviewEventRepository extends BaseRepository implements ImageReviewEventRepositoryInterface
{
    /**
     * @return Builder<ImageReviewEvent>
     */
    protected function query(): Builder
    {
        return ImageReviewEvent::query();
    }

    public function listForImage(Image $image, array $with = []): Collection
    {
        return $this->query()
            ->with($with)
            ->where('image_id', $image->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
