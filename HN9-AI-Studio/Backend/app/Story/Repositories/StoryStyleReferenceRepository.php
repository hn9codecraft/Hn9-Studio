<?php

declare(strict_types=1);

namespace App\Story\Repositories;

use App\Story\Contracts\StoryStyleReferenceRepositoryInterface;
use App\Story\Models\StoryStyleBible;
use App\Story\Models\StoryStyleReference;
use Illuminate\Support\Collection;

final class StoryStyleReferenceRepository implements StoryStyleReferenceRepositoryInterface
{
    public function listForStyleBible(StoryStyleBible $style): Collection
    {
        return StoryStyleReference::query()
            ->where('story_style_bible_id', $style->id)
            ->orderByDesc('version')
            ->get();
    }

    public function findByUuidForStyleBible(StoryStyleBible $style, string $uuid): ?StoryStyleReference
    {
        return StoryStyleReference::query()
            ->where('story_style_bible_id', $style->id)
            ->where('uuid', $uuid)
            ->with(['styleBible.workspace.project'])
            ->first();
    }

    public function nextVersion(StoryStyleBible $style): int
    {
        $max = (int) StoryStyleReference::query()
            ->where('story_style_bible_id', $style->id)
            ->max('version');

        return $max + 1;
    }

    public function create(StoryStyleBible $style, array $attributes): StoryStyleReference
    {
        return StoryStyleReference::query()->create([
            ...$attributes,
            'story_style_bible_id' => $style->id,
        ])->loadMissing(['styleBible.workspace.project']);
    }

    public function update(StoryStyleReference $reference, array $attributes): StoryStyleReference
    {
        $reference->fill($attributes);
        $reference->save();

        return $reference->refresh()->loadMissing(['styleBible.workspace.project']);
    }
}
