<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\PublishJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublishJob>
 */
class PublishJobFactory extends Factory
{
    protected $model = PublishJob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'generated_content_id' => null,
            'generated_asset_id' => null,
            'user_id' => User::factory(),
            'channel' => fake()->randomElement(['instagram', 'youtube', 'linkedin']),
            'status' => 'queued',
            'scheduled_at' => now()->addDay(),
            'payload' => [],
            'attempts' => 0,
        ];
    }
}
