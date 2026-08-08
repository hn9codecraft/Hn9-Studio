<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\DTOs\Generation\GenerationRequestData;
use App\Models\Project;

/**
 * Coordinates the existing generation pipeline: persist the generation request,
 * prepare and record a prompt, dispatch it through the provider stack, and
 * persist the generated content and asset records.
 */
interface ExecutionOrchestratorInterface
{
    /**
     * Execute the generation workflow for a project request.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function execute(Project $project, GenerationRequestData $data, array $options = []): array;
}
