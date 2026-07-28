<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\ProjectInput;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends RepositoryInterface<ProjectInput>
 */
interface ProjectInputRepositoryInterface extends RepositoryInterface
{
    /**
     * All input briefs recorded for a project, newest first.
     *
     * @return Collection<int, ProjectInput>
     */
    public function forProject(int $projectId): Collection;
}
