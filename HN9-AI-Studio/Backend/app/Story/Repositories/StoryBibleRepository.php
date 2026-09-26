<?php

declare(strict_types=1);

namespace App\Story\Repositories;

use App\Story\Contracts\StoryBibleRepositoryInterface;
use App\Story\Models\StoryBible;
use App\Story\Models\StoryWorkspace;
use App\Story\Support\StoryBibleAudioDefaults;

final class StoryBibleRepository implements StoryBibleRepositoryInterface
{
    public function findByWorkspace(StoryWorkspace $workspace): ?StoryBible
    {
        return StoryBible::query()
            ->where('story_workspace_id', $workspace->getKey())
            ->first();
    }

    public function firstOrCreateForWorkspace(StoryWorkspace $workspace): StoryBible
    {
        return StoryBible::query()->firstOrCreate(
            ['story_workspace_id' => $workspace->getKey()],
            ['audio_defaults' => StoryBibleAudioDefaults::normalize(null)],
        );
    }

    public function update(StoryBible $bible, array $attributes): StoryBible
    {
        $bible->fill($attributes);
        $bible->save();

        return $bible->refresh();
    }
}
