<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowRun>
 */
class WorkflowRunFactory extends Factory
{
    protected $model = WorkflowRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'workflow_key' => fake()->randomElement(['reel.pipeline', 'blog.pipeline', 'post.pipeline']),
            'status' => 'pending',
            'current_stage' => null,
            'total_steps' => fake()->numberBetween(5, 12),
            'completed_steps' => 0,
            'context' => [],
            'error' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_steps' => $attributes['total_steps'] ?? 10,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
            'duration_ms' => 300000,
        ]);
    }
}
