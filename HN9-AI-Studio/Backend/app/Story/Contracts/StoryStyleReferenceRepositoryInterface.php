<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Story\Models\StoryStyleBible;
use App\Story\Models\StoryStyleReference;
use Illuminate\Support\Collection;

interface StoryStyleReferenceRepositoryInterface
{
    /**
     * @return Collection<int, StoryStyleReference>
     */
    public function listForStyleBible(StoryStyleBible $style): Collection;

    public function findByUuidForStyleBible(StoryStyleBible $style, string $uuid): ?StoryStyleReference;

    public function nextVersion(StoryStyleBible $style): int;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(StoryStyleBible $style, array $attributes): StoryStyleReference;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(StoryStyleReference $reference, array $attributes): StoryStyleReference;
}
