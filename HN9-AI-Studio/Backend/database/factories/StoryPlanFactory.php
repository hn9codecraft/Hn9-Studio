<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Story\Enums\StoryPlanStatus;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryWorkspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryPlan>
 */
class StoryPlanFactory extends Factory
{
    protected $model = StoryPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_workspace_id' => StoryWorkspace::factory(),
            'title' => null,
            'idea' => 'Aarav continues into the mountain cave.',
            'requested_duration_seconds' => 60,
            'duration_unit' => 'seconds',
            'status' => StoryPlanStatus::Draft->value,
            'current_version_id' => null,
        ];
    }
}
