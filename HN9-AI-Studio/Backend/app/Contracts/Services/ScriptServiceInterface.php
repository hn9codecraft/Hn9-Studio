<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\DTOs\Script\CreateScriptData;
use App\DTOs\Script\UpdateScriptData;
use App\Models\Project;
use App\Models\Script;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Business operations for project scripts.
 */
interface ScriptServiceInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Script>
     */
    public function paginateForProject(Project $project, int $perPage = 15, array $filters = []): LengthAwarePaginator;

    public function getByUuid(string $uuid): Script;

    public function create(CreateScriptData $data, ?User $causer = null): Script;

    public function update(Script $script, UpdateScriptData $data, ?User $causer = null): Script;

    public function delete(Script $script, ?User $causer = null): bool;
}
