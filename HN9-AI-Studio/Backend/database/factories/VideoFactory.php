<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\VideoAspectRatio;
use App\Enums\VideoDuration;
use App\Enums\VideoStatus;
use App\Models\Project;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    protected $model = Video::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(4),
            'prompt' => fake()->paragraph(),
            'negative_prompt' => fake()->optional()->sentence(),
            'aspect_ratio' => fake()->randomElement(VideoAspectRatio::values()),
            'duration' => fake()->randomElement(VideoDuration::cases())->value,
            'status' => VideoStatus::Draft->value,
            'provider' => null,
            'provider_job_id' => null,
            'output_url' => null,
            'metadata' => null,
        ];
    }
}
