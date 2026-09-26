<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Story\Enums\StoryReelStatus;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryWorkspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryReel>
 */
class StoryReelFactory extends Factory
{
    protected $model = StoryReel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_workspace_id' => StoryWorkspace::factory(),
            'title' => 'Reel 01',
            'description' => null,
            'sequence' => 1,
            'status' => StoryReelStatus::Draft->value,
            'total_duration_seconds' => 0,
            'source_plan_id' => null,
            'source_plan_version_id' => null,
        ];
    }
}
