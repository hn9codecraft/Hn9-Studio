<?php

declare(strict_types=1);

namespace App\Story\Repositories;

use App\Story\Contracts\StoryCharacterRepositoryInterface;
use App\Story\Enums\StoryCharacterStatus;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryWorkspace;
use Illuminate\Support\Collection;

final class StoryCharacterRepository implements StoryCharacterRepositoryInterface
{
    public function listForWorkspace(StoryWorkspace $workspace): Collection
    {
        return StoryCharacter::query()
            ->where('story_workspace_id', $workspace->id)
            ->where('status', '!=', StoryCharacterStatus::Archived->value)
            ->with('approvedReference')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function findByUuidForWorkspace(StoryWorkspace $workspace, string $uuid): ?StoryCharacter
    {
        return StoryCharacter::query()
            ->where('story_workspace_id', $workspace->id)
            ->where('uuid', $uuid)
            ->with(['approvedReference', 'workspace.project'])
            ->first();
    }

    public function create(StoryWorkspace $workspace, array $attributes): StoryCharacter
    {
        $maxOrder = (int) StoryCharacter::query()
            ->where('story_workspace_id', $workspace->id)
            ->max('sort_order');

        return StoryCharacter::query()->create([
            ...$attributes,
            'story_workspace_id' => $workspace->id,
            'status' => $attributes['status'] ?? StoryCharacterStatus::Draft->value,
            'sort_order' => $attributes['sort_order'] ?? ($maxOrder + 1),
        ])->loadMissing(['approvedReference', 'workspace.project']);
    }

    public function update(StoryCharacter $character, array $attributes): StoryCharacter
    {
        $character->fill($attributes);
        $character->save();

        return $character->refresh()->loadMissing(['approvedReference', 'workspace.project']);
    }
}
