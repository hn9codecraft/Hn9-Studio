<?php

declare(strict_types=1);

namespace App\Contracts\Services\PromptRuntime;

interface PromptVariableResolverInterface
{
    /**
     * Replace placeholder variables in a template.
     *
     * @param  array<string, mixed>  $variables
     */
    public function resolve(string $template, array $variables = []): string;

    /**
     * Extract placeholders from a template.
     *
     * @return list<string>
     */
    public function placeholders(string $template): array;
}
