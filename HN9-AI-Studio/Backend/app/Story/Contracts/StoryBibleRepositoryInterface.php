<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Story\Models\StoryBible;
use App\Story\Models\StoryWorkspace;

interface StoryBibleRepositoryInterface
{
    public function findByWorkspace(StoryWorkspace $workspace): ?StoryBible;

    public function firstOrCreateForWorkspace(StoryWorkspace $workspace): StoryBible;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(StoryBible $bible, array $attributes): StoryBible;
}
