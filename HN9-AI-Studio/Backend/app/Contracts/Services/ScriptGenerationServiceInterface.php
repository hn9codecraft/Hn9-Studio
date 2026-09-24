<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\Models\Project;
use App\Models\Script;
use App\Models\User;

/**
 * Creates studio scripts from the existing generation pipeline.
 */
interface ScriptGenerationServiceInterface
{
    /**
     * Run the M10.1 pipeline for deliverable_type=script and persist a new
     * studio Script. Never mutates an existing script body.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function generate(Project $project, array $input, ?User $causer = null, ?Script $parent = null): array;
}
