<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Image;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<Image>
 */
interface ImageRepositoryInterface extends RepositoryInterface
{
    /**
     * A page of image requests belonging to one project.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $with
     * @return LengthAwarePaginator<int, Image>
     */
    public function paginateForProject(int $projectId, int $perPage = 15, array $filters = [], array $with = []): LengthAwarePaginator;
}
