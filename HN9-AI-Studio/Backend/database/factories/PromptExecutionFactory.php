<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AgentExecution;
use App\Models\PromptExecution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromptExecution>
 */
class PromptExecutionFactory extends Factory
{
    protected $model = PromptExecution::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_execution_id' => AgentExecution::factory(),
            'ai_provider_id' => null,
            'template_key' => fake()->randomElement(['blog/article', 'instagram/reel', 'seo/meta']),
            'template_version' => '1.0.0',
            'model' => null,
            'status' => 'pending',
            'rendered_prompt' => null,
            'variables' => ['topic' => fake()->words(3, true)],
            'response' => null,
        ];
    }
}
