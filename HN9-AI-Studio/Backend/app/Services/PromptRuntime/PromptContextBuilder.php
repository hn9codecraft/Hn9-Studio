<?php

declare(strict_types=1);

namespace App\Services\PromptRuntime;

use App\Contracts\Services\PromptRuntime\PromptContextBuilderInterface;
use App\Models\Project;

/**
 * Combines project, brand and runtime metadata into a single prompt context.
 */
final readonly class PromptContextBuilder implements PromptContextBuilderInterface
{
    public function __construct(
        private BrandContextService $brandContext,
    ) {}

    public function build(Project $project, array $options = []): array
    {
        return $this->brandContext->forProject($project, $options);
    }
}
