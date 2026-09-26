<?php

declare(strict_types=1);

namespace App\Story\Repositories;

use App\Story\Contracts\StoryCharacterReferenceRepositoryInterface;
use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use Illuminate\Support\Collection;

final class StoryCharacterReferenceRepository implements StoryCharacterReferenceRepositoryInterface
{
    public function listForCharacter(StoryCharacter $character): Collection
    {
        return StoryCharacterReference::query()
            ->where('story_character_id', $character->id)
            ->orderByDesc('version')
            ->get();
    }

    public function findByUuidForCharacter(StoryCharacter $character, string $uuid): ?StoryCharacterReference
    {
        return StoryCharacterReference::query()
            ->where('story_character_id', $character->id)
            ->where('uuid', $uuid)
            ->with(['character.workspace.project'])
            ->first();
    }

    public function nextVersion(StoryCharacter $character): int
    {
        $max = (int) StoryCharacterReference::query()
            ->where('story_character_id', $character->id)
            ->max('version');

        return $max + 1;
    }

    public function create(StoryCharacter $character, array $attributes): StoryCharacterReference
    {
        return StoryCharacterReference::query()->create([
            ...$attributes,
            'story_character_id' => $character->id,
        ])->loadMissing(['character.workspace.project']);
    }

    public function update(StoryCharacterReference $reference, array $attributes): StoryCharacterReference
    {
        $reference->fill($attributes);
        $reference->save();

        return $reference->refresh()->loadMissing(['character.workspace.project']);
    }
}
