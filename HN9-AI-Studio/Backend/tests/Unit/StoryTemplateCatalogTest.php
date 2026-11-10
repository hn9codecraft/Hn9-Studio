<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Story\Support\StoryTemplateCatalog;
use Tests\TestCase;

final class StoryTemplateCatalogTest extends TestCase
{
    public function test_user_text_is_substituted_as_data_and_capped(): void
    {
        $catalog = new StoryTemplateCatalog;
        $template = $catalog->get('company_journey');

        $idea = $catalog->composeIdea($template, [
            'topic' => "Line one\nA desk\x00\x07",
            'duration_seconds' => 180,
            'language' => 'hi',
            'tone' => 'Calm',
            'fields' => [
                'company_name' => "Hn9\x0B",
                'milestones' => '',
                'vision' => null,
                'unknown' => 'ignored',
            ],
        ]);

        $this->assertStringContainsString('3 min', $idea);
        $this->assertStringContainsString('Hn9', $idea);
        $this->assertStringContainsString('Hindi', $idea);
        $this->assertStringContainsString('Calm', $idea);
        $this->assertStringNotContainsString('{', $idea);
        $this->assertStringNotContainsString('}', $idea);
        $this->assertStringNotContainsString('ignored', $idea);
        $this->assertStringNotContainsString("\x00", $idea);
        $this->assertStringNotContainsString("\x07", $idea);
        $this->assertStringContainsString("Line one\nA desk", $idea);

        $capped = $catalog->composeIdea($template, [
            'topic' => 'Hn9',
            'duration_seconds' => 180,
            'language' => 'en',
            'fields' => ['company_name' => 'Hn9', 'milestones' => str_repeat('A', 20000)],
        ]);
        $this->assertSame(10000, mb_strlen($capped));
        $this->assertStringNotContainsString('{', $capped);
        $this->assertStringNotContainsString('}', $capped);
    }

    public function test_public_templates_hide_the_scaffold(): void
    {
        $public = (new StoryTemplateCatalog)->all();

        $this->assertSame(['company_journey', 'product_ad', 'reel'], array_keys($public));
        foreach ($public as $template) {
            $this->assertArrayNotHasKey('idea_scaffold', $template);
            $this->assertSame([40, 60, 180], $template['allowed_durations']);
        }
    }
}
