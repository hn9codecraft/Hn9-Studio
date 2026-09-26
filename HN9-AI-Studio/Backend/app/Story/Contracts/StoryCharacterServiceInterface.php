<?php

declare(strict_types=1);

namespace App\Story\Contracts;

use App\Models\Project;
use App\Models\User;
use App\Story\Models\StoryCharacter;
use Illuminate\Support\Collection;

interface StoryCharacterServiceInterface
{
    /**
     * @return Collection<int, StoryCharacter>
     */
    public function listForProject(Project $project): Collection;

    public function getForProject(Project $project, string $characterUuid): StoryCharacter;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Project $project, array $attributes): StoryCharacter;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Project $project, string $characterUuid, array $attributes): StoryCharacter;

    public function archive(Project $project, string $characterUuid, ?User $actor = null): StoryCharacter;
}
