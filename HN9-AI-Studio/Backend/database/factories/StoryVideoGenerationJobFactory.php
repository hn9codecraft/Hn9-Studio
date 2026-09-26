<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Models\StoryWorkspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StoryVideoGenerationJob>
 */
class StoryVideoGenerationJobFactory extends Factory
{
    protected $model = StoryVideoGenerationJob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_workspace_id' => StoryWorkspace::factory(),
            'story_reel_id' => null,
            'story_scene_id' => null,
            'capability' => StoryVideoCapability::TextToVideo->value,
            'provider_key' => 'catalog.alpha',
            'model_key' => 'catalog-alpha-default',
            'operation_id' => null,
            'status' => StoryVideoJobStatus::Queued->value,
            'async_mode' => 'async_poll',
            'idempotency_key' => (string) Str::uuid(),
            'request_payload' => [],
            'routing' => [],
            'provider_metadata' => [],
            'retry_count' => 0,
            'timed_out' => false,
        ];
    }
}
