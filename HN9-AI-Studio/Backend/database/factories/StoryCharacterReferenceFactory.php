<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Story\Enums\StoryCharacterReferenceRole;
use App\Story\Enums\StoryCharacterReferenceSource;
use App\Story\Enums\StoryCharacterReferenceStatus;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StoryCharacterReference>
 */
class StoryCharacterReferenceFactory extends Factory
{
    protected $model = StoryCharacterReference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'story_character_id' => StoryCharacter::factory(),
            'source' => StoryCharacterReferenceSource::Uploaded->value,
            'role' => StoryCharacterReferenceRole::Primary->value,
            'version' => 1,
            'status' => StoryCharacterReferenceStatus::Draft->value,
            'disk' => 'images',
            'path' => 'story-characters/'.$uuid.'.png',
            'original_filename' => 'reference.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => 128,
            'width' => 64,
            'height' => 64,
            'checksum' => hash('sha256', 'reference'),
            'prompt' => null,
            'provider' => null,
            'model' => null,
            'generation' => null,
        ];
    }

    public function pendingReview(): static
    {
        return $this->state(fn (): array => [
            'status' => StoryCharacterReferenceStatus::PendingReview->value,
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => StoryCharacterReferenceStatus::Approved->value,
            'submitted_at' => now()->subMinute(),
            'reviewed_at' => now(),
        ]);
    }
}
