<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\DTOs\Generation\GenerationRequestData;
use App\Models\Project;
use App\Models\ProjectInput;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Accepts and validates requests to generate a deliverable, persisting them as
 * project-input briefs.
 *
 * IMPORTANT: this records *intent* only. It performs no generation, enqueues
 * no jobs and starts no workflow — those belong to a later sprint.
 */
interface GenerationRequestServiceInterface
{
    /**
     * Validate business rules and record the generation request as a brief.
     */
    public function submit(Project $project, GenerationRequestData $data, ?User $causer = null): ProjectInput;

    /**
     * List inputs recorded for the project.
     *
     * @return Collection<int, ProjectInput>
     */
    public function forProject(Project $project): Collection;
}
