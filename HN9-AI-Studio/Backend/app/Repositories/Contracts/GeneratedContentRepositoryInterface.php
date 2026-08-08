<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\GeneratedContent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends RepositoryInterface<GeneratedContent>
 */
interface GeneratedContentRepositoryInterface extends RepositoryInterface
{
    /**
     * Paginated content, optionally scoped to the projects one user owns.
     *
     * Passing null for the owner returns content across every project, which is
     * how an administrator lists.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $with
     * @return LengthAwarePaginator<int, GeneratedContent>
     */
    public function paginateForOwner(?int $userId, int $perPage = 15, array $filters = [], array $with = []): LengthAwarePaginator;

    /**
     * All generated content for a project.
     *
     * @param  list<string>  $with
     * @return Collection<int, GeneratedContent>
     */
    public function forProject(int $projectId, array $with = []): Collection;

    /**
     * All generated content of a given type for a project.
     *
     * @return Collection<int, GeneratedContent>
     */
    public function forProjectOfType(int $projectId, string $type): Collection;

    /**
     * The highest version number recorded for a project/type pair (0 if none).
     */
    public function latestVersion(int $projectId, string $type): int;
}
