<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AiProvider;
use Illuminate\Database\Seeder;

/**
 * Provider catalog rows only. Never writes keys, users, or settings.
 */
class AiProviderSeeder extends Seeder
{
    public function run(): void
    {
        $this->ensure();
    }

    public function ensure(?string $only = null): ?AiProvider
    {
        $found = null;
        foreach ($this->rows() as $slug => $attributes) {
            if ($only !== null && $slug !== $only) {
                continue;
            }

            $provider = AiProvider::withTrashed()->updateOrCreate(['slug' => $slug], $attributes);
            if ($provider->trashed()) {
                $provider->restore();
            }
            $found = $provider;
        }

        return $only === null ? null : $found;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function rows(): array
    {
        return [
            'openai' => $this->row('OpenAI', 'llm', 'ai.providers.openai', 'ai.providers.openai.base_url', 'ai.providers.openai.priority'),
            'gemini' => $this->row('Gemini', 'llm', 'ai.providers.gemini', 'ai.providers.gemini.base_url', 'ai.providers.gemini.priority'),
            'elevenlabs' => $this->row('ElevenLabs', 'tts', 'ai.providers.elevenlabs', 'ai.providers.elevenlabs.base_url', 'ai.providers.elevenlabs.priority'),
            'runway' => $this->row('Runway', 'video', 'story_video.live_providers.runway', 'story_video.live_providers.runway.base_url', 'story_video.live_providers.runway.priority'),
            'luma' => $this->row('Luma', 'video', 'story_video.live_providers.luma', 'story_video.live_providers.luma.base_url', 'story_video.live_providers.luma.priority'),
            'seedance' => $this->row('Seedance', 'video', 'story_video.live_providers.seedance', 'story_video.live_providers.seedance.base_url', 'story_video.live_providers.seedance.priority'),
            'claude' => $this->row('Claude', 'llm', 'ai.providers.claude', 'ai.providers.claude.base_url', 'ai.providers.claude.priority'),
            'openrouter' => $this->row('OpenRouter', 'llm', 'ai.providers.openrouter', 'ai.providers.openrouter.base_url', 'ai.providers.openrouter.priority'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $name, string $category, string $configPath, string $baseUrlPath, string $priorityPath): array
    {
        $baseUrl = config($baseUrlPath);

        return [
            'name' => $name,
            'category' => $category,
            'status' => 'active',
            'base_url' => is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
            'priority' => (int) config($priorityPath, 0),
            'metadata' => ['config_path' => $configPath],
        ];
    }
}
