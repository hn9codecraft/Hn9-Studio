<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\Models\Image;
use App\Models\ImageReviewEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface ImageReviewServiceInterface
{
    public function submit(Image $image, User $actor, ?string $comment = null): Image;

    public function approve(Image $image, User $actor, ?string $comment = null): Image;

    public function requestRework(Image $image, User $actor, string $comment): Image;

    public function recordReworked(Image $image, User $actor): ImageReviewEvent;

    /**
     * @return Collection<int, ImageReviewEvent>
     */
    public function history(Image $image): Collection;
}
