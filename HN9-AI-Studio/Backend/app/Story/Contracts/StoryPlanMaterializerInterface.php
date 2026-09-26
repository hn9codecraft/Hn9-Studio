<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Models\Project;
use App\Story\Models\StoryReel;

interface StoryPlanMaterializerInterface
{
    /**
     * @return array{reel: StoryReel, created: bool, scene_count: int}
     */
    public function materialize(Project $project, string $planUuid, string $versionUuid): array;
}
