<?php

declare(strict_types=1);

namespace App\Story\Repositories;

use App\Story\Contracts\StorySceneRepositoryInterface;
use App\Story\Enums\StorySceneStatus;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use Illuminate\Support\Collection;

final class StorySceneRepository implements StorySceneRepositoryInterface
{
    public function listForReel(StoryReel $reel, bool $includeArchived = false): Collection
    {
        $query = StoryScene::query()
            ->where('story_reel_id', $reel->id)
            ->with(['sourcePlanVersion', 'reel.workspace.project'])
            ->orderBy('sequence')
            ->orderBy('id');

        if (! $includeArchived) {
            $query->where('status', '!=', StorySceneStatus::Archived->value);
        }

        return $query->get();
    }

    public function findByUuidForReel(StoryReel $reel, string $uuid): ?StoryScene
    {
        return StoryScene::query()
            ->where('story_reel_id', $reel->id)
            ->where('uuid', $uuid)
            ->with(['sourcePlanVersion', 'reel.workspace.project'])
            ->first();
    }

    public function create(StoryReel $reel, array $attributes): StoryScene
    {
        return StoryScene::query()->create([
            ...$attributes,
            'story_reel_id' => $reel->id,
            'status' => $attributes['status'] ?? StorySceneStatus::Draft->value,
        ])->loadMissing(['sourcePlanVersion', 'reel.workspace.project']);
    }

    public function update(StoryScene $scene, array $attributes): StoryScene
    {
        $scene->fill($attributes);
        $scene->save();

        return $scene->refresh()->loadMissing(['sourcePlanVersion', 'reel.workspace.project']);
    }

    public function findActiveByUuids(StoryReel $reel, array $orderedUuids): Collection
    {
        $scenes = StoryScene::query()
            ->where('story_reel_id', $reel->id)
            ->where('status', '!=', StorySceneStatus::Archived->value)
            ->whereIn('uuid', $orderedUuids)
            ->get()
            ->keyBy('uuid');

        $ordered = collect();
        foreach ($orderedUuids as $uuid) {
            if ($scenes->has($uuid)) {
                $ordered->push($scenes->get($uuid));
            }
        }

        return $ordered;
    }
}
