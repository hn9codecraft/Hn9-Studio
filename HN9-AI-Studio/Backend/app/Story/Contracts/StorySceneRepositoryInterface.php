<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use Illuminate\Support\Collection;

interface StorySceneRepositoryInterface
{
    public function listForReel(StoryReel $reel, bool $includeArchived = false): Collection;

    public function findByUuidForReel(StoryReel $reel, string $uuid): ?StoryScene;

    public function create(StoryReel $reel, array $attributes): StoryScene;

    public function update(StoryScene $scene, array $attributes): StoryScene;

    /** @param  list<string>  $orderedUuids */
    public function findActiveByUuids(StoryReel $reel, array $orderedUuids): Collection;
}
