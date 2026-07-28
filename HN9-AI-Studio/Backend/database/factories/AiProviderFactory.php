<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiProvider>
 */
class AiProviderFactory extends Factory
{
    protected $model = AiProvider::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'name' => $name,
            'category' => fake()->randomElement(['llm', 'image', 'video', 'tts']),
            'status' => 'active',
            'base_url' => fake()->optional()->url(),
            'priority' => fake()->numberBetween(0, 100),
            'capabilities' => [],
            'metadata' => [],
        ];
    }
}
