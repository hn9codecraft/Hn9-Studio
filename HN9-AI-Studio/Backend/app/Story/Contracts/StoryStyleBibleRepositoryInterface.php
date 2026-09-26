<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Story\Models\StoryStyleBible;
use App\Story\Models\StoryWorkspace;

interface StoryStyleBibleRepositoryInterface
{
    public function findByWorkspace(StoryWorkspace $workspace): ?StoryStyleBible;

    public function firstOrCreateForWorkspace(StoryWorkspace $workspace): StoryStyleBible;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(StoryStyleBible $style, array $attributes): StoryStyleBible;
}
