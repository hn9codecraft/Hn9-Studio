<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExportStatus;
use App\Models\Export;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Export>
 */
class ExportFactory extends Factory
{
    protected $model = Export::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'project_id' => Project::factory(),
            'status' => ExportStatus::Queued->value,
            'disk' => 'exports',
            'path' => null,
            'filename' => null,
            'size' => null,
            'fingerprint' => fake()->sha256(),
            'error' => null,
            'manifest' => null,
            'completed_at' => null,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ExportStatus::Processing->value,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ExportStatus::Completed->value,
            'filename' => 'project-export.zip',
            'path' => 'exports/project-export.zip',
            'size' => 128,
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ExportStatus::Failed->value,
            'error' => 'The export package could not be created.',
        ]);
    }
}
