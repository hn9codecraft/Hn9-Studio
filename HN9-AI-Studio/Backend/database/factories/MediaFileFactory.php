<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MediaFile;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaFile>
 */
class MediaFileFactory extends Factory
{
    protected $model = MediaFile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $extension = fake()->randomElement(['png', 'jpg', 'mp4', 'mp3']);

        return [
            'mediable_type' => Project::class,
            'mediable_id' => Project::factory(),
            'disk' => fake()->randomElement(['images', 'videos', 'voice']),
            'path' => 'generated/'.fake()->uuid().'.'.$extension,
            'original_name' => fake()->word().'.'.$extension,
            'mime_type' => fake()->randomElement(['image/png', 'image/jpeg', 'video/mp4', 'audio/mpeg']),
            'extension' => $extension,
            'size' => fake()->numberBetween(1024, 10_485_760),
            'checksum' => fake()->sha256(),
            'collection' => fake()->randomElement(['images', 'videos', 'voice']),
            'meta' => [],
        ];
    }
}
