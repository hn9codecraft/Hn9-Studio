<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\DTOs\Project\CreateProjectData;
use App\DTOs\Project\UpdateProjectData;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Business operations for projects. Holds the rules (slug uniqueness, status
 * transitions, auditing); persistence is delegated to the repository.
 */
interface ProjectServiceInterface
{
    /**
     * A page of the user's projects.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Project>
     */
    public function paginateForUser(User $user, int $perPage = 15, array $filters = []): LengthAwarePaginator;

    /**
     * Resolve a project by its public UUID (or throw).
     */
    public function getByUuid(string $uuid): Project;

    public function create(CreateProjectData $data, ?User $causer = null): Project;

    public function update(Project $project, UpdateProjectData $data, ?User $causer = null): Project;

    public function changeStatus(Project $project, ProjectStatus $status, ?User $causer = null): Project;

    public function delete(Project $project, ?User $causer = null): bool;
}
