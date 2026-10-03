<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AI\Contracts\CircuitBreakerInterface;
use App\Story\Contracts\LiveStoryVideoProviderAdapterInterface;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoInputType;
use App\Story\Support\StoryGenerationUnitCalculator;
use App\Story\Video\Adapters\LiveStoryVideoAdapterFactory;
use App\Story\Video\StoryCapabilityRouter;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoInput;
use App\Story\Video\StoryVideoProviderOrchestrator;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class StoryVideoProviderOrchestratorTest extends TestCase
{
    public function test_runway_is_selected_when_it_can_make_the_unit(): void
    {
        $decision = $this->orchestrator()->route($this->request(10));

        $this->assertTrue($decision->matched);
        $this->assertSame('video.runway', $decision->providerKey);
        $this->assertSame('gen4.5', $decision->modelKey);
        $this->assertSame('preferred_provider', $this->reason($decision->reasons, 'video.runway'));
        $this->assertSame('eligible', $this->reason($decision->reasons, 'video.luma'));
        $this->assertSame('eligible', $this->reason($decision->reasons, 'video.seedance'));
    }

    public function test_a_seven_second_unit_excludes_luma_and_still_prefers_runway(): void
    {
        $decision = $this->orchestrator()->route($this->request(7));

        $this->assertSame('video.runway', $decision->providerKey);
        $this->assertSame('unsupported_duration', $this->reason($decision->reasons, 'video.luma'));
        $this->assertSame('eligible', $this->reason($decision->reasons, 'video.seedance'));
    }

    public function test_reference_to_video_can_only_use_seedance(): void
    {
        Storage::fake('images');
        Storage::disk('images')->put('cast/mira.png', 'image-bytes');
        $decision = $this->orchestrator()->route($this->request(10, StoryVideoCapability::ReferenceToVideo, [
            new StoryVideoInput(StoryVideoInputType::ReferenceImage, metadata: ['disk' => 'images', 'path' => 'cast/mira.png', 'mime' => 'image/png']),
        ]));

        $this->assertSame('video.seedance', $decision->providerKey);
        $this->assertSame('dreamina-seedance-2-0-260128', $decision->modelKey);
        $this->assertSame('fallback_to_next_eligible', $this->reason($decision->reasons, 'video.seedance'));
        $this->assertSame('capability_unsupported', $this->reason($decision->reasons, 'video.runway'));
        $this->assertSame('capability_unsupported', $this->reason($decision->reasons, 'video.luma'));
    }

    public function test_an_unavailable_preferred_provider_falls_back_before_submission(): void
    {
        config(['story_video.live_providers.runway.enabled' => false]);
        $decision = $this->orchestrator()->route($this->request(10));

        $this->assertSame('video.luma', $decision->providerKey);
        $this->assertSame('ray-3.2', $decision->modelKey);
        $this->assertSame('fallback_to_next_eligible', $this->reason($decision->reasons, 'video.luma'));
    }

    public function test_fallback_can_be_turned_off(): void
    {
        config([
            'story_video.routing.fallback_enabled' => false,
            'story_video.live_providers.runway.enabled' => false,
        ]);
        $decision = $this->orchestrator()->route($this->request(10));

        $this->assertFalse($decision->matched);
        $this->assertSame('VIDEO_CAPABILITY_NOT_AVAILABLE', $decision->errorCode);
        $this->assertSame('Video generation is not available for this scene right now.', $decision->errorMessage);
    }

    public function test_an_open_circuit_removes_the_preferred_provider(): void
    {
        $circuits = $this->app->make(CircuitBreakerInterface::class);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $circuits->recordFailure('video.runway');
        }
        $decision = $this->orchestrator()->route($this->request(10));

        $this->assertSame('video.luma', $decision->providerKey);
        $this->assertSame('circuit_open', $this->reason($decision->reasons, 'video.runway'));
    }

    public function test_gemini_is_not_a_production_candidate(): void
    {
        $router = new StoryCapabilityRouter;
        foreach ($this->adapters() as $adapter) {
            $router->register($adapter);
        }
        foreach ($this->app->make(LiveStoryVideoAdapterFactory::class)->fromConfig([
            'runway' => $this->providerConfig('runway', 'video.live', 900, 'gen4.5'),
        ], false) as $adapter) {
            $router->register($adapter);
        }
        $decision = (new StoryVideoProviderOrchestrator($router, $this->app->make(CircuitBreakerInterface::class)))
            ->route($this->request(10));

        $this->assertSame('video.runway', $decision->providerKey);
        $this->assertArrayNotHasKey('video.live', array_column($decision->reasons, 'reason', 'provider'));
    }

    public function test_image_to_video_keeps_the_preferred_provider_when_the_picture_is_stored(): void
    {
        Storage::fake('images');
        Storage::disk('images')->put('cast/mira.png', 'image-bytes');
        $decision = $this->orchestrator()->route($this->request(10, StoryVideoCapability::ImageToVideo, [
            new StoryVideoInput(StoryVideoInputType::Image, metadata: ['disk' => 'images', 'path' => 'cast/mira.png', 'mime' => 'image/png']),
        ]));

        $this->assertSame('video.runway', $decision->providerKey);
        $this->assertSame('gen4.5', $decision->modelKey);
        $this->assertSame('unsupported_duration', $this->reason($decision->reasons, 'video.luma'));
        $this->assertSame('eligible', $this->reason($decision->reasons, 'video.seedance'));

        $five = $this->orchestrator()->route($this->request(5, StoryVideoCapability::ImageToVideo, [
            new StoryVideoInput(StoryVideoInputType::Image, metadata: ['disk' => 'images', 'path' => 'cast/mira.png', 'mime' => 'image/png']),
        ]));
        $this->assertSame('video.runway', $five->providerKey);
        $this->assertSame('eligible', $this->reason($five->reasons, 'video.luma'));
    }

    public function test_an_unsupported_length_names_the_clip_and_submits_nothing(): void
    {
        $decision = $this->orchestrator()->route($this->request(1));

        $this->assertFalse($decision->matched);
        $this->assertSame('VIDEO_CAPABILITY_NOT_AVAILABLE', $decision->errorCode);
        $this->assertSame('None of the connected video services supports this clip length.', $decision->errorMessage);
        $this->assertSame('unsupported_duration', $this->reason($decision->reasons, 'video.runway'));
        $this->assertSame('unsupported_duration', $this->reason($decision->reasons, 'video.luma'));
        $this->assertSame('unsupported_duration', $this->reason($decision->reasons, 'video.seedance'));
    }

    public function test_five_seconds_is_eligible_for_every_production_provider(): void
    {
        $decision = $this->orchestrator()->route($this->request(5));

        $this->assertSame('video.runway', $decision->providerKey);
        $this->assertSame('eligible', $this->reason($decision->reasons, 'video.luma'));
        $this->assertSame('eligible', $this->reason($decision->reasons, 'video.seedance'));
    }

    public function test_no_connected_provider_is_not_described_as_ready(): void
    {
        $decision = (new StoryVideoProviderOrchestrator(new StoryCapabilityRouter, $this->app->make(CircuitBreakerInterface::class)))
            ->route($this->request(10));

        $this->assertFalse($decision->matched);
        $this->assertSame('GENERATION_NOT_ENABLED', $decision->errorCode);
        $this->assertSame('Video generation is not configured yet.', $decision->errorMessage);
    }

    public function test_the_same_request_routes_the_same_way_twice(): void
    {
        $orchestrator = $this->orchestrator();
        $request = $this->request(7);

        $this->assertSame(
            $orchestrator->route($request)->toArray(),
            $orchestrator->route($request)->toArray(),
        );
    }

    #[DataProvider('scenes')]
    public function test_each_scene_unit_keeps_its_own_length(int $sceneSeconds, string $expected): void
    {
        $orchestrator = $this->orchestrator();
        $lengths = [];
        foreach ((new StoryGenerationUnitCalculator)->split($sceneSeconds) as $slot) {
            $decision = $orchestrator->route($this->request($slot->durationSeconds));
            $this->assertTrue($decision->matched, (string) $slot->durationSeconds);
            $this->assertSame($slot->durationSeconds, $this->request($slot->durationSeconds)->durationSeconds);
            $lengths[] = $slot->durationSeconds;
        }

        $this->assertSame($expected, implode('+', $lengths));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function scenes(): array
    {
        return [
            '17' => [17, '10+7'],
            '30' => [30, '10+10+10'],
            '40' => [40, '10+10+10+10'],
            '47' => [47, '10+10+10+10+7'],
            '50' => [50, '10+10+10+10+10'],
            '60' => [60, '10+10+10+10+10+10'],
        ];
    }

    private function orchestrator(): StoryVideoProviderOrchestrator
    {
        $router = new StoryCapabilityRouter;
        foreach ($this->adapters() as $adapter) {
            $router->register($adapter);
        }

        return new StoryVideoProviderOrchestrator($router, $this->app->make(CircuitBreakerInterface::class));
    }

    /**
     * @return list<LiveStoryVideoProviderAdapterInterface>
     */
    private function adapters(): array
    {
        return $this->app->make(LiveStoryVideoAdapterFactory::class)->fromConfig([
            'seedance' => $this->providerConfig('seedance', 'video.seedance', 300, 'dreamina-seedance-2-0-260128'),
            'luma' => $this->providerConfig('luma', 'video.luma', 250, 'ray-3.2'),
            'runway' => $this->providerConfig('runway', 'video.runway', 240, 'gen4.5'),
        ], false);
    }

    /**
     * @return array<string, mixed>
     */
    private function providerConfig(string $vendor, string $key, int $priority, string $model): array
    {
        return [
            'enabled' => config('story_video.live_providers.'.$vendor.'.enabled', true),
            'key' => $key,
            'label' => $vendor,
            'priority' => $priority,
            'api_key' => 'fake-'.$vendor,
            'model' => $model,
        ];
    }

    /**
     * @param  list<StoryVideoInput>  $inputs
     */
    private function request(int $seconds, StoryVideoCapability $capability = StoryVideoCapability::TextToVideo, array $inputs = []): StoryVideoGenerationRequest
    {
        return new StoryVideoGenerationRequest(capability: $capability, prompt: 'A quiet harbor', durationSeconds: $seconds, inputs: $inputs);
    }

    /**
     * @param  list<array<string, mixed>>  $reasons
     */
    private function reason(array $reasons, string $provider): ?string
    {
        foreach ($reasons as $reason) {
            if (($reason['provider'] ?? null) === $provider) {
                return (string) $reason['reason'];
            }
        }

        return null;
    }
}
