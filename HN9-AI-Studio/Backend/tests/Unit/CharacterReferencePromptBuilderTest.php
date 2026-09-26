<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Story\Models\StoryCharacter;
use App\Story\Support\CharacterReferencePromptBuilder;
use PHPUnit\Framework\TestCase;

final class CharacterReferencePromptBuilderTest extends TestCase
{
    public function test_prompt_includes_structured_fields_only(): void
    {
        $character = new StoryCharacter([
            'name' => 'Aarav',
            'appearance' => '12-year-old boy, black short hair, round face',
            'clothing' => 'Blue hoodie, black pants, white shoes',
            'personality' => 'Curious and brave',
            'special_details' => 'Small scar above left eyebrow',
            'short_description' => '',
            'age' => '12',
        ]);

        $prompt = (new CharacterReferencePromptBuilder)->build($character);

        $this->assertStringContainsString('Do not invent unrelated details', $prompt);
        $this->assertStringContainsString('Character name: Aarav', $prompt);
        $this->assertStringContainsString('Appearance: 12-year-old boy', $prompt);
        $this->assertStringContainsString('Clothing: Blue hoodie', $prompt);
        $this->assertStringContainsString('Special identifying details: Small scar', $prompt);
    }
}
