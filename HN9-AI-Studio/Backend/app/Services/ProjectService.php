<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\ProjectServiceInterface;
use App\DTOs\Project\CreateProjectData;
use App\DTOs\Project\UpdateProjectData;
use App\Enums\ProjectStatus;
use App\Exceptions\WorkflowException;
use App\Models\Project;
use App\Models\User;
use App\Repositories\Contracts\ProjectRepositoryInterface;
use App\Support\DomainHelper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Business rules for projects: slug uniqueness, status-transition validation
 * and audit logging. Persistence is delegated to the repository; this service
 * holds no database access of its own.
 */
final readonly class ProjectService implements ProjectServiceInterface
{
    public function __construct(
        private ProjectRepositoryInterface $projects,
        private ActivityLoggerInterface $activity,
    ) {}

    public function paginateForUser(User $user, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return $this->projects->paginateForUser($user->getKey(), $perPage, $filters);
    }

    public function getByUuid(string $uuid): Project
    {
        return $this->projects->findByUuidOrFail($uuid);
    }

    public function create(CreateProjectData $data, ?User $causer = null): Project
    {
        $attributes = $data->toArray();
        $attributes['slug'] = $this->resolveSlug($data->user_id, $data->slug ?? $data->name);

        $project = $this->projects->create($attributes);

        $this->activity->log('project.created', $project, $causer, 'Project created');

        return $project;
    }

    public function update(Project $project, UpdateProjectData $data, ?User $causer = null): Project
    {
        $attributes = $data->toArray();

        if (isset($attributes['slug']) || isset($attributes['name'])) {
            $seed = $attributes['slug'] ?? $attributes['name'] ?? $project->slug;
            $attributes['slug'] = $this->resolveSlug($project->user_id, $seed, $project->getKey());
        }

        if (isset($attributes['status'])) {
            $this->assertStatusTransition($project, ProjectStatus::from((string) $attributes['status']));
        }

        $project = $this->projects->update($project, $attributes);

        $this->activity->log('project.updated', $project, $causer, 'Project updated');

        return $project;
    }

    public function changeStatus(Project $project, ProjectStatus $status, ?User $causer = null): Project
    {
        $this->assertStatusTransition($project, $status);

        $project = $this->projects->update($project, ['status' => $status->value]);

        $this->activity->log('project.status_changed', $project, $causer, "Status changed to {$status->value}");

        return $project;
    }

    public function delete(Project $project, ?User $causer = null): bool
    {
        $deleted = $this->projects->delete($project);

        if ($deleted) {
            $this->activity->log('project.deleted', $project, $causer, 'Project deleted');
        }

        return $deleted;
    }

    /**
     * Produce a unique slug for the user, ignoring the project being updated.
     */
    private function resolveSlug(int $userId, string $seed, ?int $ignoreId = null): string
    {
        return DomainHelper::uniqueSlug(
            $seed,
            fn (string $slug): bool => $this->projects->slugExistsForUser($userId, $slug, $ignoreId),
        );
    }

    /**
     * Guard an illegal project status transition.
     */
    private function assertStatusTransition(Project $project, ProjectStatus $target): void
    {
        $current = ProjectStatus::tryFrom((string) $project->status) ?? ProjectStatus::Draft;

        if (! $current->canTransitionTo($target)) {
            throw new WorkflowException(
                message: "Cannot change project status from [{$current->value}] to [{$target->value}].",
                errorCode: 'project_invalid_status_transition',
                statusCode: 409,
                context: ['from' => $current->value, 'to' => $target->value],
            );
        }
    }
}
