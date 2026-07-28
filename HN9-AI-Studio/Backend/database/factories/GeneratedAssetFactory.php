<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GeneratedAsset;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneratedAsset>
 */
class GeneratedAssetFactory extends Factory
{
    protected $model = GeneratedAsset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'generated_content_id' => null,
            'workflow_run_id' => null,
            'agent_execution_id' => null,
            'type' => fake()->randomElement(['image', 'video', 'voice', 'thumbnail']),
            'provider' => null,
            'status' => 'pending',
            'prompt' => fake()->sentence(),
            'metadata' => [],
        ];
    }
}
