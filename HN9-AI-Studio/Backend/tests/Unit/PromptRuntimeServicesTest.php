<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Project;
use App\Services\PromptRuntime\BrandContextService;
use App\Services\PromptRuntime\PromptRenderer;
use App\Services\PromptRuntime\PromptTemplateResolver;
use App\Services\PromptRuntime\PromptVariableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromptRuntimeServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_context_service_loads_brand_and_project_runtime_context(): void
    {
        $project = Project::factory()->create([
            'name' => 'Launch Campaign',
            'type' => 'marketing',
            'settings' => [
                'tone' => 'brand-led',
                'business_type' => 'agency',
            ],
            'metadata' => [
                'industry' => ['AI Automation'],
                'writing_style' => 'clear and premium',
            ],
        ]);

        $context = app(BrandContextService::class)->forProject($project, [
            'language' => 'hi',
        ]);

        $this->assertSame('HN9', $context['brand_name']);
        $this->assertSame('hi', $context['language']);
        $this->assertSame('brand-led', $context['tone']);
        $this->assertSame('agency', $context['business_type']);
        $this->assertSame('clear and premium', $context['writing_style']);
        $this->assertSame('AI Automation', $context['industry'][0]);
    }

    public function test_prompt_template_resolver_reads_template_from_prompt_catalog(): void
    {
        $expected = [
            'blog', 'caption', 'carousel', 'email', 'facebook', 'hashtags',
            'image', 'instagram', 'landing-page', 'linkedin', 'proposal',
            'reel', 'sales', 'script', 'seo', 'storyboard', 'thumbnail',
            'video', 'voice', 'website', 'youtube',
        ];

        foreach ($expected as $key) {
            $template = app(PromptTemplateResolver::class)->resolve($key);
            $this->assertNotSame('', trim($template));
        }

        $this->assertStringContainsString('Write a', app(PromptTemplateResolver::class)->resolve('blog'));
        $this->assertStringContainsString('{{company_name}}', app(PromptTemplateResolver::class)->resolve('blog'));
    }

    public function test_prompt_variable_resolver_replaces_placeholders_and_preserves_missing_coverage(): void
    {
        $rendered = app(PromptVariableResolver::class)->resolve(
            'Write for {{company_name}} about {{topic}}. CTA: {{cta}}',
            [
                'company_name' => 'HN9',
                'topic' => 'AI automation',
                'cta' => 'Book a strategy call',
            ],
        );

        $this->assertSame('Write for HN9 about AI automation. CTA: Book a strategy call', $rendered);

        $listRendered = app(PromptVariableResolver::class)->resolve(
            'Focus on {{industry}}.',
            ['industry' => ['AI Automation', 'Software Development']],
        );

        $this->assertSame('Focus on AI Automation, Software Development.', $listRendered);

        $this->expectException(\InvalidArgumentException::class);
        app(PromptVariableResolver::class)->resolve('Hello {{missing}}', []);
    }

    public function test_prompt_renderer_validates_context_and_returns_preview_data(): void
    {
        $renderer = app(PromptRenderer::class);

        $rendered = $renderer->render('Write for {{company_name}} about {{topic}}.', [
            'company_name' => 'HN9',
            'topic' => 'AI automation',
        ]);

        $this->assertSame('Write for HN9 about AI automation.', $rendered);

        $preview = $renderer->preview('Write for {{company_name}} about {{topic}}.', [
            'company_name' => 'HN9',
            'topic' => 'AI automation',
        ]);

        $this->assertSame('Write for HN9 about AI automation.', $preview['rendered']);
        $this->assertTrue($preview['is_valid']);
        $this->assertSame(['company_name', 'topic'], $preview['placeholders']);
    }

    public function test_brand_context_service_applies_project_override_precedence(): void
    {
        $project = Project::factory()->create([
            'name' => 'Override Campaign',
            'type' => 'social',
            'settings' => [
                'tone' => 'project-specific',
                'business_type' => 'saas',
                'brand_name' => 'Client Brand',
            ],
            'metadata' => [
                'industry' => ['Fintech', 'Healthcare'],
                'writing_style' => 'confident and direct',
            ],
        ]);

        $context = app(BrandContextService::class)->forProject($project, [
            'language' => 'en',
        ]);

        $this->assertSame('project-specific', $context['tone']);
        $this->assertSame('saas', $context['business_type']);
        $this->assertSame('Fintech', $context['industry'][0]);
        $this->assertSame('confident and direct', $context['writing_style']);
        $this->assertSame('Client Brand', $context['brand_name']);
    }
}
