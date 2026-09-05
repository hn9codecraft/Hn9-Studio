<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImageAspectRatio;
use App\Enums\ImageStatus;
use App\Models\Image;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Image>
 */
class ImageFactory extends Factory
{
    protected $model = Image::class;

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
            'aspect_ratio' => fake()->randomElement(ImageAspectRatio::values()),
            'status' => ImageStatus::Draft->value,
            'provider' => null,
            'provider_job_id' => null,
            'output_url' => null,
            'metadata' => null,
        ];
    }
}
