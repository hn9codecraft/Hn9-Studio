<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryCharacterRepositoryInterface;
use App\Story\Contracts\StoryCharacterServiceInterface;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Story\Enums\StoryCharacterStatus;
use App\Story\Exceptions\StoryCharacterException;
use App\Story\Exceptions\StoryException;
use App\Story\Models\StoryCharacter;
use Illuminate\Support\Collection;

final readonly class StoryCharacterService implements StoryCharacterServiceInterface
{
    public function __construct(
        private StoryWorkspaceServiceInterface $workspaces,
        private StoryCharacterRepositoryInterface $characters,
    ) {}

    public function listForProject(Project $project): Collection
    {
        $workspace = $this->workspaces->workspaceForProject($project);

        return $this->characters->listForWorkspace($workspace);
    }

    public function getForProject(Project $project, string $characterUuid): StoryCharacter
    {
        $workspace = $this->workspaces->workspaceForProject($project);
        $character = $this->characters->findByUuidForWorkspace($workspace, $characterUuid);

        if ($character === null) {
            throw StoryException::notFound('Character');
        }

        return $character;
    }

    public function create(Project $project, array $attributes): StoryCharacter
    {
        $workspace = $this->workspaces->workspaceForProject($project);

        return $this->characters->create($workspace, $attributes);
    }

    public function update(Project $project, string $characterUuid, array $attributes): StoryCharacter
    {
        $character = $this->getForProject($project, $characterUuid);

        if (! $character->allowsEdit()) {
            throw StoryCharacterException::archived();
        }

        return $this->characters->update($character, $attributes);
    }

    public function archive(Project $project, string $characterUuid, ?User $actor = null): StoryCharacter
    {
        $character = $this->getForProject($project, $characterUuid);

        if ($character->statusEnum() === StoryCharacterStatus::Archived) {
            return $character;
        }

        return $this->characters->update($character, [
            'status' => StoryCharacterStatus::Archived->value,
        ]);
    }
}
