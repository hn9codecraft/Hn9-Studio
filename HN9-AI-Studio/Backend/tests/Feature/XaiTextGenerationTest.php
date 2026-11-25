<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\DTOs\ProviderConfigDTO;
use App\AI\Providers\Xai\XaiClient;
use App\AI\Providers\Xai\XaiConfig;
use App\AI\Providers\Xai\XaiImageParameterMap;
use App\AI\Providers\Xai\XaiModelRegistry;
use App\AI\Providers\Xai\XaiProvider;
use App\AI\Providers\Xai\XaiResponseNormalizer;
use App\AI\Providers\Xai\XaiTimestampMapper;
use App\AI\Providers\Xai\XaiTokenCounter;
use App\AI\Providers\Xai\XaiUsageCalculator;
use App\AI\Requests\TextRequest;
use App\Models\Project;
use App\Models\User;
use App\Story\Support\StoryPlanDurationCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConfiguresXai;
use Tests\TestCase;

final class XaiTextGenerationTest extends TestCase
{
    use ConfiguresXai;
    use RefreshDatabase;

    public function test_text_uses_the_responses_api_and_the_default_model(): void
    {
        Http::fake([
            'https://api.x.ai/v1/responses' => Http::response([
                'model' => 'grok-4.3',
                'output_text' => 'Hello from Grok',
                'usage' => ['input_tokens' => 4, 'output_tokens' => 6],
            ]),
        ]);

        $response = $this->provider()->generateText(new TextRequest(prompt: 'Hello'));

        $this->assertSame('Hello from Grok', $response->text);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.x.ai/v1/responses'
            && $request['model'] === 'grok-4.3'
            && $request['input'] === 'Hello'
            && $request->hasHeader('Authorization', 'Bearer '.self::XAI_TEST_KEY));
    }

    public function test_an_empty_role_keeps_the_previous_text_provider(): void
    {
        config()->set('ai.providers.openai', [
            'enabled' => true,
            'api_key' => 'sk-test-openai-key',
            'base_url' => 'https://api.openai.com/v1',
            'default_model' => 'configured-openai-model',
            'models' => ['configured-openai-model'],
            'priority' => 100,
            'timeout' => 5,
            'max_retries' => 0,
        ]);
        $this->enableXai(['ai.story_text_provider' => '']);
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'model' => 'configured-openai-model',
                'output_text' => $this->planJson(),
            ]),
            'https://api.x.ai/*' => Http::response(['output_text' => 'should not be called'], 500),
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $planId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans", [
                'idea' => 'A short cave story for the routing check.',
                'requested_duration_seconds' => 30,
            ])
            ->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/story/projects/{$project->uuid}/plans/{$planId}/generate")
            ->assertOk()
            ->assertJsonPath('data.current_version.provider', 'openai');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.openai.com'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api.x.ai'));
    }

    private function provider(): XaiProvider
    {
        $dto = ProviderConfigDTO::fromArray('xai', [
            'api_key' => self::XAI_TEST_KEY,
            'base_url' => 'https://api.x.ai/v1',
            'default_model' => 'grok-4.3',
            'models' => ['grok-4.3'],
            'image_models' => ['grok-imagine-image'],
            'image_default_model' => 'grok-imagine-image',
        ]);
        $config = XaiConfig::fromProviderConfig($dto);
        $usage = new XaiUsageCalculator($config);

        return new XaiProvider(
            new XaiClient($this->app->make(Factory::class), $config),
            new XaiModelRegistry($config),
            $usage,
            new XaiResponseNormalizer($usage, new XaiTimestampMapper),
            new XaiTokenCounter,
            $config,
            new XaiImageParameterMap,
        );
    }

    private function planJson(): string
    {
        $calc = (new StoryPlanDurationCalculator)->calculate(30);
        $scenes = [];
        foreach ($calc['segments'] as $segment) {
            $scenes[] = [
                'sequence' => $segment['sequence'],
                'start_second' => $segment['start_second'],
                'end_second' => $segment['end_second'],
                'duration_seconds' => $segment['duration_seconds'],
                'title' => 'Scene '.$segment['sequence'],
                'story' => 'Story beat '.$segment['sequence'],
                'characters' => ['Aarav'],
                'location' => 'Cave',
                'dialogue' => ['Aarav: Ready.'],
                'narration' => 'He steps in.',
                'visual_prompt' => 'Cave mouth',
                'motion_prompt' => 'Push in',
                'audio_direction' => 'Wind',
                'continuity' => [
                    'previous_scene' => '',
                    'next_scene' => '',
                    'character_state' => 'curious',
                    'environment_state' => 'dim',
                ],
            ];
        }

        return json_encode([
            'title' => 'Cave',
            'logline' => 'Aarav enters.',
            'master_story' => 'Aarav enters the cave.',
            'total_duration_seconds' => 30,
            'scene_duration_target_seconds' => 30,
            'remainder_strategy' => $calc['remainder_strategy'],
            'scenes' => $scenes,
        ], JSON_THROW_ON_ERROR);
    }
}
