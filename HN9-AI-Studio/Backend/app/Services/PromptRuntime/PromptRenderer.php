<?php

declare(strict_types=1);

namespace App\Services\PromptRuntime;

use App\Contracts\Services\PromptRuntime\PromptRendererInterface;
use App\Contracts\Services\PromptRuntime\PromptVariableResolverInterface;

/**
 * Renders a prompt from a template and variables.
 *
 * This is a runtime-only helper used to prepare final prompt text for later
 * model execution. It never calls AI providers.
 */
final readonly class PromptRenderer implements PromptRendererInterface
{
    public function __construct(
        private PromptVariableResolverInterface $resolver,
    ) {}

    public function render(string $template, array $variables = []): string
    {
        $this->assertValidTemplate($template);

        return $this->resolver->resolve($template, $variables);
    }

    public function preview(string $template, array $variables = []): array
    {
        $this->assertValidTemplate($template);

        $placeholders = $this->resolver->placeholders($template);
        $rendered = $this->resolver->resolve($template, $variables);

        return [
            'rendered' => $rendered,
            'is_valid' => $this->isTemplateValid($template, $variables),
            'placeholders' => $placeholders,
        ];
    }

    private function assertValidTemplate(string $template): void
    {
        if (trim($template) === '') {
            throw new \InvalidArgumentException('Prompt template cannot be empty.');
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function isTemplateValid(string $template, array $variables): bool
    {
        try {
            $this->resolver->resolve($template, $variables);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
