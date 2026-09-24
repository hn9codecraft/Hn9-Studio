<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\Models\User;
use App\Models\Video;
use App\Models\VideoReviewEvent;
use Illuminate\Database\Eloquent\Collection;

interface VideoReviewServiceInterface
{
    public function submit(Video $video, User $actor, ?string $comment = null): Video;

    public function approve(Video $video, User $actor, ?string $comment = null): Video;

    public function requestRework(Video $video, User $actor, string $comment): Video;

    public function recordReworked(Video $video, User $actor): VideoReviewEvent;

    /**
     * @return Collection<int, VideoReviewEvent>
     */
    public function history(Video $video): Collection;
}
