<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ScriptStatus;
use App\Models\Project;
use App\Models\Script;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Script>
 */
class ScriptFactory extends Factory
{
    protected $model = Script::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(4),
            'body' => fake()->optional()->paragraphs(3, true),
            'status' => fake()->randomElement(ScriptStatus::values()),
        ];
    }
}
