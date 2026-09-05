<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProjectAssetSource;
use App\Enums\ProjectAssetStatus;
use App\Enums\ProjectAssetType;
use App\Models\Project;
use App\Models\ProjectAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectAsset>
 */
class ProjectAssetFactory extends Factory
{
    protected $model = ProjectAsset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(3),
            'type' => fake()->randomElement(ProjectAssetType::values()),
            'source' => ProjectAssetSource::Manual->value,
            'status' => ProjectAssetStatus::Draft->value,
            'file_url' => null,
            'mime_type' => null,
            'notes' => fake()->optional()->sentence(),
            'metadata' => null,
        ];
    }
}
