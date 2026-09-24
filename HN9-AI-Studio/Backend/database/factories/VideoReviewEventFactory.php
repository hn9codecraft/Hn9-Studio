<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\VideoReviewAction;
use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoReviewEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoReviewEvent>
 */
class VideoReviewEventFactory extends Factory
{
    protected $model = VideoReviewEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_id' => Video::factory(),
            'user_id' => User::factory(),
            'action' => VideoReviewAction::SubmittedForReview->value,
            'comment' => null,
            'from_status' => VideoStatus::Completed->value,
            'to_status' => VideoStatus::PendingReview->value,
            'created_at' => now(),
        ];
    }
}
