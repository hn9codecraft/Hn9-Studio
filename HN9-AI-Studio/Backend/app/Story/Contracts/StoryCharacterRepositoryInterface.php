<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryWorkspace;
use Illuminate\Support\Collection;

interface StoryCharacterRepositoryInterface
{
    /**
     * @return Collection<int, StoryCharacter>
     */
    public function listForWorkspace(StoryWorkspace $workspace): Collection;

    public function findByUuidForWorkspace(StoryWorkspace $workspace, string $uuid): ?StoryCharacter;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(StoryWorkspace $workspace, array $attributes): StoryCharacter;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(StoryCharacter $character, array $attributes): StoryCharacter;
}
