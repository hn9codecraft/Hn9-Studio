<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Story\Enums\StoryVideoAsyncMode;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Enums\StoryVideoInputType;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Video\CatalogStoryVideoAdapter;
use App\Story\Video\StoryCapabilityRouter;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoInput;
use App\Story\Video\StoryVideoModelSpec;
use App\Story\Video\UnavailableStoryVideoEngine;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class StoryCapabilityRouterTest extends TestCase
{
    public function test_empty_router_lists_all_capabilities_as_unavailable(): void
    {
        $router = new StoryCapabilityRouter;
        $catalog = $router->catalog();

        $this->assertCount(6, $catalog);
        $this->assertSame(StoryVideoCapability::values(), array_map(
            static fn ($route): string => $route->capability->value,
            $catalog,
        ));

        foreach ($catalog as $route) {
            $this->assertFalse($route->available);
            $this->assertSame([], $route->adapterKeys);
        }
    }

    public function test_router_selects_by_capability_not_vendor_name(): void
    {
        $router = new StoryCapabilityRouter;
        $router->register(new CatalogStoryVideoAdapter(
            adapterKey: 'catalog.alpha',
            capabilities: [StoryVideoCapability::TextToVideo, StoryVideoCapability::Audio],
            available: true,
            durations: [8],
            aspectRatios: ['16:9', '9:16'],
            inputTypes: ['text'],
        ));
        $router->register(new CatalogStoryVideoAdapter(
            adapterKey: 'catalog.beta',
            capabilities: [StoryVideoCapability::ImageToVideo],
            available: false,
            durations: [8],
            aspectRatios: ['16:9'],
            inputTypes: ['image'],
        ));

        $text = $router->route(StoryVideoCapability::TextToVideo);
        $this->assertTrue($text->available);
        $this->assertSame(['catalog.alpha'], $text->adapterKeys);
        $this->assertSame([8], $text->durations);
        $this->assertSame(['16:9', '9:16'], $text->aspectRatios);
        $this->assertSame(['text'], $text->inputTypes);

        $image = $router->route(StoryVideoCapability::ImageToVideo);
        $this->assertFalse($image->available);
        $this->assertSame(['catalog.beta'], $image->adapterKeys);

        $extend = $router->route(StoryVideoCapability::VideoExtend);
        $this->assertFalse($extend->available);
        $this->assertSame([], $extend->adapterKeys);
    }

    public function test_resolve_applies_priority_duration_and_fallback(): void
    {
        $router = new StoryCapabilityRouter;
        $router->register(new CatalogStoryVideoAdapter(
            adapterKey: 'catalog.alpha',
            label: 'A',
            capabilities: [StoryVideoCapability::TextToVideo],
            available: true,
            priority: 100,
            durations: [30],
            aspectRatios: ['9:16'],
            inputTypes: ['text'],
            audio: true,
            models: [
                new StoryVideoModelSpec(
                    providerKey: 'catalog.alpha',
                    modelKey: 'alpha-default',
                    displayName: 'Alpha',
                    capabilities: [StoryVideoCapability::TextToVideo],
                    priority: 100,
                ),
            ],
        ));
        $router->register(new CatalogStoryVideoAdapter(
            adapterKey: 'catalog.beta',
            label: 'B',
            capabilities: [StoryVideoCapability::TextToVideo],
            available: true,
            priority: 90,
            durations: [30],
            aspectRatios: ['9:16'],
            inputTypes: ['text'],
            models: [
                new StoryVideoModelSpec(
                    providerKey: 'catalog.beta',
                    modelKey: 'beta-default',
                    displayName: 'Beta',
                    capabilities: [StoryVideoCapability::TextToVideo],
                    priority: 90,
                ),
            ],
        ));

        $decision = $router->resolve(new StoryVideoGenerationRequest(
            capability: StoryVideoCapability::TextToVideo,
            prompt: 'demo',
            durationSeconds: 30,
            aspectRatio: '9:16',
        ));

        $this->assertTrue($decision->matched);
        $this->assertSame('catalog.alpha', $decision->providerKey);
        $this->assertSame('alpha-default', $decision->modelKey);
        $this->assertSame(['catalog.beta'], $decision->fallbackProviders);

        $noMatch = $router->resolve(new StoryVideoGenerationRequest(
            capability: StoryVideoCapability::TextToVideo,
            durationSeconds: 12,
            aspectRatio: '9:16',
        ));
        $this->assertFalse($noMatch->matched);
        $this->assertSame(StoryVideoErrorCode::CapabilityNotAvailable->value, $noMatch->errorCode);
    }

    public function test_engine_never_performs_provider_io(): void
    {
        Http::fake();

        $router = new StoryCapabilityRouter;
        $router->register(new CatalogStoryVideoAdapter(
            adapterKey: 'catalog.alpha',
            capabilities: [StoryVideoCapability::TextToVideo],
            available: true,
        ));
        $engine = new UnavailableStoryVideoEngine($router);

        $this->assertTrue($engine->route(StoryVideoCapability::TextToVideo)->available);
        $this->assertCount(6, $engine->catalog());

        try {
            $engine->execute(StoryVideoCapability::TextToVideo, ['prompt' => 'demo']);
            $this->fail('Story generation must not run in foundation sprints.');
        } catch (\App\Story\Exceptions\StoryGenerationNotAvailableException $exception) {
            $this->assertSame('story_generation_not_available', $exception->errorCode());
            $this->assertSame(501, $exception->statusCode());
        }

        Http::assertNothingSent();
    }

    public function test_registered_adapter_keys_are_vendor_independent(): void
    {
        $router = app(\App\Story\Contracts\StoryCapabilityRouterInterface::class);

        $this->assertNotEmpty($router->adapters());

        foreach ($router->adapters() as $adapter) {
            $key = strtolower($adapter->key());
            $this->assertStringNotContainsString('seedance', $key);
            $this->assertStringNotContainsString('higgsfield', $key);
            $this->assertStringNotContainsString('runway', $key);
            $this->assertStringNotContainsString('kling', $key);
            $this->assertStringNotContainsString('veo', $key);
            $this->assertStringStartsWith('catalog.', $key);
        }
    }

    public function test_status_normalization_and_catalog_submit_blocked(): void
    {
        $this->assertSame(StoryVideoJobStatus::Processing, StoryVideoJobStatus::normalize('in_progress'));
        $this->assertSame(StoryVideoJobStatus::Completed, StoryVideoJobStatus::normalize('succeeded'));

        $adapter = new CatalogStoryVideoAdapter(
            adapterKey: 'catalog.alpha',
            capabilities: [StoryVideoCapability::TextToVideo],
            available: true,
            mode: StoryVideoAsyncMode::AsyncPoll,
        );

        $this->expectException(StoryVideoEngineException::class);
        $adapter->submit(new StoryVideoGenerationRequest(
            capability: StoryVideoCapability::TextToVideo,
            prompt: 'x',
            inputs: [new StoryVideoInput(StoryVideoInputType::Text)],
        ));
    }
}
