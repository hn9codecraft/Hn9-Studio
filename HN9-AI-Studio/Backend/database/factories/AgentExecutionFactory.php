<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AgentExecution;
use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentExecution>
 */
class AgentExecutionFactory extends Factory
{
    protected $model = AgentExecution::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_run_id' => WorkflowRun::factory(),
            'ai_provider_id' => null,
            'agent_key' => fake()->randomElement(['planner', 'research', 'seo', 'script', 'caption']),
            'agent_version' => '1.0.0',
            'status' => 'pending',
            'attempt' => 1,
            'input' => [],
            'output' => null,
            'tokens_used' => null,
            'cost' => null,
        ];
    }
}
