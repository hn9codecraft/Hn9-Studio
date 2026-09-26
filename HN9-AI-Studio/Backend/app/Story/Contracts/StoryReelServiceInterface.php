<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Models\Project;
use App\Story\Models\StoryReel;
use Illuminate\Support\Collection;

interface StoryReelServiceInterface
{
    public function listForProject(Project $project): Collection;

    public function getForProject(Project $project, string $reelUuid): StoryReel;

    public function create(Project $project, array $attributes): StoryReel;

    public function update(Project $project, string $reelUuid, array $attributes): StoryReel;

    public function archive(Project $project, string $reelUuid): StoryReel;

    /** @param  list<string>  $orderedUuids */
    public function reorder(Project $project, array $orderedUuids): Collection;
}
