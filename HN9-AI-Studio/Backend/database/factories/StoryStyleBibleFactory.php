<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Story\Models\StoryStyleBible;
use App\Story\Models\StoryWorkspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryStyleBible>
 */
class StoryStyleBibleFactory extends Factory
{
    protected $model = StoryStyleBible::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_workspace_id' => StoryWorkspace::factory(),
            'visual_style' => null,
            'animation_style' => null,
            'lighting' => null,
            'camera_style' => null,
            'color_direction' => null,
            'environment_style' => null,
            'mood' => null,
            'rendering_style' => null,
            'visual_quality' => null,
            'art_direction_notes' => null,
            'aspect_ratio' => null,
            'approved_reference_id' => null,
        ];
    }
}
