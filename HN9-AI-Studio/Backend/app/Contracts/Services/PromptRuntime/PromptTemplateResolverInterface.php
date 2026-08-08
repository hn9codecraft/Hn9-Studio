<?php

declare(strict_types=1);

namespace App\Contracts\Services\PromptRuntime;

interface PromptTemplateResolverInterface
{
    /**
     * Resolve a prompt template by logical key.
     */
    public function resolve(string $key): string;
}
