<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Story\Enums\StoryVideoCapability;
use App\Story\Models\StoryScene;
use App\Story\Support\StoryVideoPromptComposer;
use Tests\TestCase;

final class StoryVideoPromptComposerTest extends TestCase
{
    public function test_provider_prompt_limits_match_the_adapter_constants(): void
    {
        $factory = app(\App\Story\Video\Adapters\LiveStoryVideoAdapterFactory::class);
        $adapters = $factory->fromConfig([
            'runway' => ['api_key' => 'test-key', 'enabled' => true, 'key' => 'video.runway', 'label' => 'Runway', 'model' => 'gen4.5'],
            'luma' => ['api_key' => 'test-key', 'enabled' => true, 'key' => 'video.luma', 'label' => 'Luma', 'model' => 'ray-3.2'],
            'seedance' => ['api_key' => 'test-key', 'enabled' => true, 'key' => 'video.seedance', 'label' => 'Seedance', 'model' => 'dreamina-seedance-2-0-260128'],
        ], true);
        $limits = [];
        foreach ($adapters as $adapter) {
            $limits[$adapter->vendor()] = [
                'prompt' => $adapter->maxPromptLength(),
                'image' => $adapter->referenceImageLimit(StoryVideoCapability::ImageToVideo),
                'reference' => $adapter->referenceImageLimit(StoryVideoCapability::ReferenceToVideo),
            ];
        }

        $this->assertSame(1000, $limits['runway']['prompt']);
        $this->assertSame(1, $limits['runway']['image']);
        $this->assertSame(0, $limits['runway']['reference']);
        $this->assertSame(6000, $limits['luma']['prompt']);
        $this->assertNull($limits['seedance']['prompt']);
        $this->assertSame(9, $limits['seedance']['reference']);
        $this->assertSame(1000, (new \ReflectionClass(\App\Story\Video\Adapters\RunwayStoryVideoAdapter::class))->getConstant('MAX_PROMPT'));
        $this->assertSame(6000, (new \ReflectionClass(\App\Story\Video\Adapters\LumaStoryVideoAdapter::class))->getConstant('MAX_PROMPT'));
    }

    public function test_automatic_base_matches_the_previous_prompt_join(): void
    {
        $scene = new StoryScene([
            'visual_prompt' => 'Wide shot',
            'motion_prompt' => 'Slow push-in',
            'story' => 'A story',
        ]);
        $composer = app(StoryVideoPromptComposer::class);
        $expected = trim(implode("\n\n", array_filter([
            $scene->visual_prompt,
            $scene->motion_prompt,
            $scene->story,
            null,
        ])));

        $this->assertSame($expected, $composer->automaticBase($scene, ''));
    }
}
