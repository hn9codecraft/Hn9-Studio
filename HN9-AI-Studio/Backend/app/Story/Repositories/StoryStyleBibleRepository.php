<?php

declare(strict_types=1);

namespace App\Story\Repositories;

use App\Story\Contracts\StoryStyleBibleRepositoryInterface;
use App\Story\Models\StoryStyleBible;
use App\Story\Models\StoryWorkspace;

final class StoryStyleBibleRepository implements StoryStyleBibleRepositoryInterface
{
    public function findByWorkspace(StoryWorkspace $workspace): ?StoryStyleBible
    {
        return StoryStyleBible::query()
            ->where('story_workspace_id', $workspace->getKey())
            ->first();
    }

    public function firstOrCreateForWorkspace(StoryWorkspace $workspace): StoryStyleBible
    {
        return StoryStyleBible::query()->firstOrCreate(
            ['story_workspace_id' => $workspace->getKey()],
            [],
        );
    }

    public function update(StoryStyleBible $style, array $attributes): StoryStyleBible
    {
        $style->fill($attributes);
        $style->save();

        return $style->refresh()->loadMissing(['approvedReference', 'workspace.project']);
    }
}
