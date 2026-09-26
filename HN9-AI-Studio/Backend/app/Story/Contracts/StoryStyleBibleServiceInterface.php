<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Models\Project;
use App\Story\Models\StoryStyleBible;

interface StoryStyleBibleServiceInterface
{
    public function styleForProject(Project $project): StoryStyleBible;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateForProject(Project $project, array $attributes): StoryStyleBible;
}
