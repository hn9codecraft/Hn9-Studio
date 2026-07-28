<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<Project>
 */
interface ProjectRepositoryInterface extends RepositoryInterface
{
    /**
     * A page of projects owned by the given user.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $with
     * @return LengthAwarePaginator<int, Project>
     */
    public function paginateForUser(int $userId, int $perPage = 15, array $filters = [], array $with = []): LengthAwarePaginator;

    /**
     * Whether the given slug is already taken for the user (optionally
     * ignoring one project id — used when updating).
     */
    public function slugExistsForUser(int $userId, string $slug, ?int $ignoreId = null): bool;
}
