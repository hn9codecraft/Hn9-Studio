<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Story\Enums\StoryWorkspaceStatus;
use App\Story\Models\StoryWorkspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryWorkspace>
 */
class StoryWorkspaceFactory extends Factory
{
    protected $model = StoryWorkspace::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'status' => StoryWorkspaceStatus::Ready->value,
        ];
    }
}
