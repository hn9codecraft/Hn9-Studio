<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Video;
use App\Models\VideoReviewEvent;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends RepositoryInterface<VideoReviewEvent>
 */
interface VideoReviewEventRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  list<string>  $with
     * @return Collection<int, VideoReviewEvent>
     */
    public function listForVideo(Video $video, array $with = []): Collection;
}
