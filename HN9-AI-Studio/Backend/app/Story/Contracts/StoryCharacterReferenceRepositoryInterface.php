<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Story\Models\StoryCharacter;
use App\Story\Models\StoryCharacterReference;
use Illuminate\Support\Collection;

interface StoryCharacterReferenceRepositoryInterface
{
    /**
     * @return Collection<int, StoryCharacterReference>
     */
    public function listForCharacter(StoryCharacter $character): Collection;

    public function findByUuidForCharacter(StoryCharacter $character, string $uuid): ?StoryCharacterReference;

    public function nextVersion(StoryCharacter $character): int;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(StoryCharacter $character, array $attributes): StoryCharacterReference;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(StoryCharacterReference $reference, array $attributes): StoryCharacterReference;
}
