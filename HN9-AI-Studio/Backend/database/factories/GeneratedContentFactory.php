<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GeneratedContent;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneratedContent>
 */
class GeneratedContentFactory extends Factory
{
    protected $model = GeneratedContent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'workflow_run_id' => null,
            'agent_execution_id' => null,
            'type' => fake()->randomElement(['script', 'caption', 'blog', 'seo', 'subtitle']),
            'channel' => fake()->randomElement(['instagram', 'youtube', 'linkedin', null]),
            'language' => fake()->randomElement(['en', 'hi', 'gu']),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraphs(2, true),
            'structured' => [],
            'status' => 'draft',
            'version' => 1,
            'metadata' => [],
        ];
    }
}
