<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Story\Enums\StorySceneStatus;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryScene>
 */
class StorySceneFactory extends Factory
{
    protected $model = StoryScene::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_reel_id' => StoryReel::factory(),
            'sequence' => 1,
            'title' => 'Scene 01',
            'duration_seconds' => 30,
            'start_second' => 0,
            'end_second' => 30,
            'status' => StorySceneStatus::Draft->value,
            'story' => 'Aarav enters the cave.',
            'characters' => ['Aarav'],
            'location' => 'Mountain cave',
            'dialogue' => [],
            'narration' => null,
            'visual_prompt' => 'Wide shot of cave entrance',
            'motion_prompt' => 'Slow push-in',
            'audio_direction' => 'Wind and dripping water',
            'continuity' => [
                'previous_scene' => null,
                'next_scene' => null,
                'character_state' => 'Cautious',
                'environment_state' => 'Dark cave mouth',
            ],
            'source_plan_version_id' => null,
        ];
    }
}
