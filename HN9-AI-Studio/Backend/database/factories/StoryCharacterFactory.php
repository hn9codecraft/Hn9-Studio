<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Story\Enums\StoryCharacterStatus;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryWorkspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryCharacter>
 */
class StoryCharacterFactory extends Factory
{
    protected $model = StoryCharacter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_workspace_id' => StoryWorkspace::factory(),
            'name' => fake()->firstName(),
            'short_description' => fake()->optional()->sentence(),
            'age' => null,
            'gender_presentation' => null,
            'appearance' => null,
            'face_description' => null,
            'hair' => null,
            'clothing' => null,
            'personality' => null,
            'voice_description' => null,
            'special_details' => null,
            'status' => StoryCharacterStatus::Draft->value,
            'sort_order' => 0,
            'approved_reference_id' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'status' => StoryCharacterStatus::Archived->value,
        ]);
    }
}
