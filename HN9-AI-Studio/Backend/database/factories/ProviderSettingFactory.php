<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiProvider;
use App\Models\ProviderSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderSetting>
 */
class ProviderSettingFactory extends Factory
{
    protected $model = ProviderSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_provider_id' => AiProvider::factory(),
            'key' => fake()->unique()->randomElement(['api_key', 'base_url', 'organization', 'timeout']),
            'value' => fake()->word(),
            'is_secret' => false,
            'environment' => 'production',
        ];
    }

    public function secret(): static
    {
        return $this->state(fn (array $attributes) => [
            'key' => 'api_key',
            'is_secret' => true,
        ]);
    }
}
