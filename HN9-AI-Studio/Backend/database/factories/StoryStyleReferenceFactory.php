<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Story\Enums\StoryStyleReferenceRole;
use App\Story\Enums\StoryStyleReferenceSource;
use App\Story\Enums\StoryStyleReferenceStatus;
use App\Story\Models\StoryStyleBible;
use App\Story\Models\StoryStyleReference;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StoryStyleReference>
 */
class StoryStyleReferenceFactory extends Factory
{
    protected $model = StoryStyleReference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'story_style_bible_id' => StoryStyleBible::factory(),
            'source' => StoryStyleReferenceSource::Uploaded->value,
            'role' => StoryStyleReferenceRole::Primary->value,
            'version' => 1,
            'status' => StoryStyleReferenceStatus::Draft->value,
            'disk' => 'images',
            'path' => 'story-styles/'.$uuid.'.png',
            'original_filename' => 'style.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => 128,
            'width' => 64,
            'height' => 64,
            'checksum' => hash('sha256', 'style'),
            'prompt' => null,
            'provider' => null,
            'model' => null,
            'generation' => null,
        ];
    }
}
