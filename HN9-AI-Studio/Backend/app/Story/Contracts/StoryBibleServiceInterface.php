<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Models\Project;
use App\Story\Models\StoryBible;

interface StoryBibleServiceInterface
{
    public function bibleForProject(Project $project): StoryBible;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateForProject(Project $project, array $attributes): StoryBible;
}
