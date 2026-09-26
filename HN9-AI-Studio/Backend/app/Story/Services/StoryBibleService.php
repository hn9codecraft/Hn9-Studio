<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Story\Contracts\StoryBibleRepositoryInterface;
use App\Story\Contracts\StoryBibleServiceInterface;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Story\Models\StoryBible;
use App\Story\Support\StoryBibleAudioDefaults;

final readonly class StoryBibleService implements StoryBibleServiceInterface
{
    public function __construct(
        private StoryWorkspaceServiceInterface $workspaces,
        private StoryBibleRepositoryInterface $bibles,
    ) {}

    public function bibleForProject(Project $project): StoryBible
    {
        $workspace = $this->workspaces->workspaceForProject($project);

        return $this->bibles->firstOrCreateForWorkspace($workspace)->loadMissing('workspace.project');
    }

    public function updateForProject(Project $project, array $attributes): StoryBible
    {
        $bible = $this->bibleForProject($project);

        if (array_key_exists('audio_defaults', $attributes)) {
            $attributes['audio_defaults'] = StoryBibleAudioDefaults::normalize(
                is_array($attributes['audio_defaults']) ? $attributes['audio_defaults'] : null,
            );
        }

        return $this->bibles->update($bible, $attributes)->loadMissing('workspace.project');
    }
}
