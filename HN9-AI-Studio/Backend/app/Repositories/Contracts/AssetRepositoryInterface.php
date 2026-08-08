<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\GeneratedAsset;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends RepositoryInterface<GeneratedAsset>
 */
interface AssetRepositoryInterface extends RepositoryInterface
{
    /**
     * All generated assets belonging to a project.
     *
     * @param  list<string>  $with
     * @return Collection<int, GeneratedAsset>
     */
    public function forProject(int $projectId, array $with = []): Collection;

    /**
     * All generated assets of a given type for a project.
     *
     * @return Collection<int, GeneratedAsset>
     */
    public function forProjectOfType(int $projectId, string $type): Collection;

    /**
     * Paginate assets for the owner of the project, with blueprint-style filters.
     */
    public function paginateForOwner(?int $userId, int $perPage = 15, array $filters = [], array $with = []): LengthAwarePaginator;
}
