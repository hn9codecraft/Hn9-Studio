<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImageReviewAction;
use App\Enums\ImageStatus;
use App\Models\Image;
use App\Models\ImageReviewEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImageReviewEvent>
 */
class ImageReviewEventFactory extends Factory
{
    protected $model = ImageReviewEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'image_id' => Image::factory(),
            'user_id' => User::factory(),
            'action' => ImageReviewAction::SubmittedForReview->value,
            'comment' => null,
            'from_status' => ImageStatus::Draft->value,
            'to_status' => ImageStatus::PendingReview->value,
            'created_at' => now(),
        ];
    }
}
