<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Image;
use App\Models\ImageReviewEvent;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends RepositoryInterface<ImageReviewEvent>
 */
interface ImageReviewEventRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  list<string>  $with
     * @return Collection<int, ImageReviewEvent>
     */
    public function listForImage(Image $image, array $with = []): Collection;
}
