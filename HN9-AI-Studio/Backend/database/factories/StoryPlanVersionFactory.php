<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Story\Enums\StoryPlanVersionStatus;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryPlanVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryPlanVersion>
 */
class StoryPlanVersionFactory extends Factory
{
    protected $model = StoryPlanVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_plan_id' => StoryPlan::factory(),
            'version' => 1,
            'status' => StoryPlanVersionStatus::Completed->value,
            'input' => [],
            'instruction' => null,
            'master_story' => 'Aarav enters the cave.',
            'plan' => [
                'title' => 'Cave Entry',
                'logline' => 'Aarav steps into darkness.',
                'total_duration_seconds' => 30,
                'scene_duration_target_seconds' => 30,
                'scenes' => [],
            ],
            'remainder_strategy' => 'exact',
        ];
    }
}
