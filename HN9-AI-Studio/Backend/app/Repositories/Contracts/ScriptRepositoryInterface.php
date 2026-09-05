<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Script;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<Script>
 */
interface ScriptRepositoryInterface extends RepositoryInterface
{
    /**
     * A page of scripts belonging to one project.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $with
     * @return LengthAwarePaginator<int, Script>
     */
    public function paginateForProject(int $projectId, int $perPage = 15, array $filters = [], array $with = []): LengthAwarePaginator;
}
