<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\ScriptServiceInterface;
use App\DTOs\Script\CreateScriptData;
use App\DTOs\Script\UpdateScriptData;
use App\Models\Project;
use App\Models\Script;
use App\Models\User;
use App\Repositories\Contracts\ScriptRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Business rules for studio scripts. Persistence is delegated to the repository.
 */
final readonly class ScriptService implements ScriptServiceInterface
{
    public function __construct(
        private ScriptRepositoryInterface $scripts,
        private ActivityLoggerInterface $activity,
    ) {}

    public function paginateForProject(Project $project, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return $this->scripts->paginateForProject($project->getKey(), $perPage, $filters, ['project']);
    }

    public function getByUuid(string $uuid): Script
    {
        return $this->scripts->findByUuidOrFail($uuid, ['project']);
    }

    public function create(CreateScriptData $data, ?User $causer = null): Script
    {
        $script = $this->scripts->create($data->toArray());
        $script->load('project');

        $this->activity->log('script.created', $script, $causer, 'Script created');

        return $script;
    }

    public function update(Script $script, UpdateScriptData $data, ?User $causer = null): Script
    {
        $script = $this->scripts->update($script, $data->toArray());
        $script->load('project');

        $this->activity->log('script.updated', $script, $causer, 'Script updated');

        return $script;
    }

    public function delete(Script $script, ?User $causer = null): bool
    {
        $deleted = $this->scripts->delete($script);

        if ($deleted) {
            $this->activity->log('script.deleted', $script, $causer, 'Script deleted');
        }

        return $deleted;
    }
}
