<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectInput;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectInput>
 */
class ProjectInputFactory extends Factory
{
    protected $model = ProjectInput::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'type' => 'brief',
            'deliverable_type' => fake()->randomElement(['reel', 'short', 'post', 'blog']),
            'platform' => fake()->randomElement(['instagram', 'youtube', 'linkedin']),
            'language' => fake()->randomElement(['en', 'hi', 'gu']),
            'topic' => fake()->sentence(3),
            'goal' => fake()->sentence(),
            'payload' => ['tone' => 'confident'],
            'source' => fake()->randomElement(['api', 'ui']),
        ];
    }
}
