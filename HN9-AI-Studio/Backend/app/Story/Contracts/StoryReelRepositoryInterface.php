<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Story\Models\StoryReel;
use App\Story\Models\StoryWorkspace;
use Illuminate\Support\Collection;

interface StoryReelRepositoryInterface
{
    public function listForWorkspace(StoryWorkspace $workspace, bool $includeArchived = false): Collection;

    public function findByUuidForWorkspace(StoryWorkspace $workspace, string $uuid): ?StoryReel;

    public function findBySourcePlanVersionId(StoryWorkspace $workspace, int $planVersionId): ?StoryReel;

    public function create(StoryWorkspace $workspace, array $attributes): StoryReel;

    public function update(StoryReel $reel, array $attributes): StoryReel;

    public function nextSequence(StoryWorkspace $workspace): int;
}
