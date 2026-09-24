<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Video;
use App\Models\VideoReviewEvent;
use App\Repositories\Contracts\VideoReviewEventRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<VideoReviewEvent>
 */
class VideoReviewEventRepository extends BaseRepository implements VideoReviewEventRepositoryInterface
{
    /**
     * @return Builder<VideoReviewEvent>
     */
    protected function query(): Builder
    {
        return VideoReviewEvent::query();
    }

    public function listForVideo(Video $video, array $with = []): Collection
    {
        return $this->query()
            ->with($with)
            ->where('video_id', $video->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
