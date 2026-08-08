<?php

declare(strict_types=1);

namespace App\Contracts\Services\PromptRuntime;

use App\Models\Project;

interface BrandContextServiceInterface
{
    /**
     * Build a brand-aware runtime context for a project. The resulting array is
     * read-only to the service layer and is used to populate prompt variables.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function forProject(Project $project, array $options = []): array;
}
