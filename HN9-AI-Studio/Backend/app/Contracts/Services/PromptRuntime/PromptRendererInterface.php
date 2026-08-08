<?php

declare(strict_types=1);

namespace App\Contracts\Services\PromptRuntime;

interface PromptRendererInterface
{
    /**
     * Render a prompt template using the supplied variable context.
     *
     * @param  array<string, mixed>  $variables
     */
    public function render(string $template, array $variables = []): string;

    /**
     * Return a preview payload for a template.
     *
     * @param  array<string, mixed>  $variables
     * @return array{rendered: string, is_valid: bool, placeholders: list<string>}
     */
    public function preview(string $template, array $variables = []): array;
}
