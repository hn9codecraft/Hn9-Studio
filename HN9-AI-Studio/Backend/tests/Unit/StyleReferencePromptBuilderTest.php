<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Story\Models\StoryStyleBible;
use App\Story\Support\StyleReferencePromptBuilder;
use PHPUnit\Framework\TestCase;

final class StyleReferencePromptBuilderTest extends TestCase
{
    public function test_prompt_includes_structured_style_fields(): void
    {
        $style = new StoryStyleBible([
            'visual_style' => '3D cinematic animation',
            'lighting' => 'Warm sunset lighting',
            'camera_style' => 'Slow cinematic camera movement',
            'environment_style' => 'Fantasy village',
            'mood' => 'Whimsical and emotional',
        ]);

        $prompt = (new StyleReferencePromptBuilder)->build($style);

        $this->assertStringContainsString('Do not invent unrelated style attributes', $prompt);
        $this->assertStringContainsString('Visual style: 3D cinematic animation', $prompt);
        $this->assertStringContainsString('Lighting: Warm sunset lighting', $prompt);
        $this->assertStringContainsString('Environment style: Fantasy village', $prompt);
        $this->assertStringContainsString('Mood: Whimsical and emotional', $prompt);
    }
}
