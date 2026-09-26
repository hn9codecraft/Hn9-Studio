<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Models\Project;
use App\Story\Models\StoryScene;
use Illuminate\Support\Collection;

interface StorySceneServiceInterface
{
    public function listForProjectReel(Project $project, string $reelUuid): Collection;

    public function getForProjectReel(Project $project, string $reelUuid, string $sceneUuid): StoryScene;

    public function create(Project $project, string $reelUuid, array $attributes): StoryScene;

    public function update(Project $project, string $reelUuid, string $sceneUuid, array $attributes): StoryScene;

    public function duplicate(Project $project, string $reelUuid, string $sceneUuid): StoryScene;

    public function archive(Project $project, string $reelUuid, string $sceneUuid): StoryScene;

    /** @param  list<string>  $orderedUuids */
    public function reorder(Project $project, string $reelUuid, array $orderedUuids): Collection;
}
