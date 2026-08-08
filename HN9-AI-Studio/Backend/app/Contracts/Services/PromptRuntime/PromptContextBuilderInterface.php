<?php

declare(strict_types=1);

namespace App\Contracts\Services\PromptRuntime;

use App\Models\Project;

interface PromptContextBuilderInterface
{
    /**
     * Combine project, brand and runtime metadata into a prompt-ready context.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(Project $project, array $options = []): array;
}
