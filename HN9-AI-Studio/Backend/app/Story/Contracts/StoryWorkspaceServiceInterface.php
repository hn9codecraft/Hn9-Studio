<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryWorkspace;
use App\Story\Video\StoryCapabilityRoute;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface StoryWorkspaceServiceInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Project>
     */
    public function selectableProjects(User $user, int $perPage, array $filters = []): LengthAwarePaginator;

    public function workspaceForProject(Project $project): StoryWorkspace;

    /**
     * @return list<StoryCapabilityRoute>
     */
    public function capabilityCatalog(): array;
}
