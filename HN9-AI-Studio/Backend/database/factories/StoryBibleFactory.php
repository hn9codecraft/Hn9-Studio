<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Story\Models\StoryBible;
use App\Story\Models\StoryWorkspace;
use App\Story\Support\StoryBibleAudioDefaults;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryBible>
 */
class StoryBibleFactory extends Factory
{
    protected $model = StoryBible::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_workspace_id' => StoryWorkspace::factory(),
            'concept' => null,
            'genre' => null,
            'audience' => null,
            'language' => null,
            'tone' => null,
            'world' => null,
            'location' => null,
            'time_period' => null,
            'narrative_style' => null,
            'video_style' => null,
            'aspect_ratio' => null,
            'default_duration' => null,
            'audio_defaults' => StoryBibleAudioDefaults::normalize(null),
        ];
    }
}
