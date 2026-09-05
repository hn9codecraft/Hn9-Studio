<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\ProjectAsset;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<ProjectAsset>
 */
interface ProjectAssetRepositoryInterface extends RepositoryInterface
{
    /**
     * A page of studio assets belonging to one project.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $with
     * @return LengthAwarePaginator<int, ProjectAsset>
     */
    public function paginateForProject(int $projectId, int $perPage = 15, array $filters = [], array $with = []): LengthAwarePaginator;
}
