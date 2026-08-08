<?php

declare(strict_types=1);

namespace App\Services\PromptRuntime;

use App\Contracts\Services\PromptRuntime\PromptTemplateResolverInterface;

/**
 * Resolves prompt templates from the static Prompt catalog. This is runtime-only
 * preparation and never invokes providers or model execution.
 */
final readonly class PromptTemplateResolver implements PromptTemplateResolverInterface
{
    /**
     * @var array<string, string>
     */
    private array $catalog;

    public function __construct()
    {
        $base = base_path('../Prompts/templates');
        $this->catalog = [
            'blog' => $this->read($base.'/blog.md'),
            'caption' => $this->read($base.'/caption.md'),
            'carousel' => $this->read($base.'/carousel.md'),
            'email' => $this->read($base.'/email.md'),
            'facebook' => $this->read($base.'/facebook.md'),
            'hashtags' => $this->read($base.'/hashtags.md'),
            'image' => $this->read($base.'/image.md'),
            'instagram' => $this->read($base.'/instagram.md'),
            'landing-page' => $this->read($base.'/landing-page.md'),
            'linkedin' => $this->read($base.'/linkedin.md'),
            'proposal' => $this->read($base.'/proposal.md'),
            'reel' => $this->read($base.'/reel.md'),
            'sales' => $this->read($base.'/sales.md'),
            'script' => $this->read($base.'/script.md'),
            'seo' => $this->read($base.'/seo.md'),
            'storyboard' => $this->read($base.'/storyboard.md'),
            'thumbnail' => $this->read($base.'/thumbnail.md'),
            'video' => $this->read($base.'/video.md'),
            'voice' => $this->read($base.'/voice.md'),
            'website' => $this->read($base.'/website.md'),
            'youtube' => $this->read($base.'/youtube.md'),
        ];
    }

    public function resolve(string $key): string
    {
        $template = $this->catalog[$key] ?? null;

        if ($template === null) {
            throw new \InvalidArgumentException("Unknown prompt template: {$key}");
        }

        return $template;
    }

    private function read(string $path): string
    {
        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException("Unable to read prompt template: {$path}");
        }

        return $content;
    }
}
