<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Story\Contracts\StoryReelRepositoryInterface;
use App\Story\Contracts\StoryReelServiceInterface;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Story\Enums\StoryReelStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Exceptions\StoryRuntimeException;
use App\Story\Models\StoryReel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class StoryReelService implements StoryReelServiceInterface
{
    public function __construct(
        private StoryWorkspaceServiceInterface $workspaces,
        private StoryReelRepositoryInterface $reels,
    ) {}

    public function listForProject(Project $project): Collection
    {
        $workspace = $this->workspaces->workspaceForProject($project);

        return $this->reels->listForWorkspace($workspace);
    }

    public function getForProject(Project $project, string $reelUuid): StoryReel
    {
        $workspace = $this->workspaces->workspaceForProject($project);
        $reel = $this->reels->findByUuidForWorkspace($workspace, $reelUuid);

        if ($reel === null) {
            throw StoryException::notFound('Reel');
        }

        return $reel;
    }

    public function create(Project $project, array $attributes): StoryReel
    {
        $workspace = $this->workspaces->workspaceForProject($project);
        $sequence = $attributes['sequence'] ?? $this->reels->nextSequence($workspace);

        return $this->reels->create($workspace, [
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'sequence' => (int) $sequence,
            'status' => StoryReelStatus::Draft->value,
            'total_duration_seconds' => 0,
        ]);
    }

    public function update(Project $project, string $reelUuid, array $attributes): StoryReel
    {
        $reel = $this->getForProject($project, $reelUuid);

        if (! $reel->allowsEdit()) {
            throw StoryRuntimeException::archived('Reel');
        }

        $payload = array_intersect_key($attributes, array_flip(['title', 'description', 'status']));

        if (isset($payload['status'])) {
            $status = StoryReelStatus::tryFrom((string) $payload['status']);
            if ($status === null || $status === StoryReelStatus::Archived) {
                unset($payload['status']);
            }
        }

        return $this->reels->update($reel, $payload);
    }

    public function archive(Project $project, string $reelUuid): StoryReel
    {
        $reel = $this->getForProject($project, $reelUuid);

        if ($reel->statusEnum() === StoryReelStatus::Archived) {
            return $reel;
        }

        return $this->reels->update($reel, [
            'status' => StoryReelStatus::Archived->value,
        ]);
    }

    public function reorder(Project $project, array $orderedUuids): Collection
    {
        $workspace = $this->workspaces->workspaceForProject($project);
        $active = $this->reels->listForWorkspace($workspace);

        if (count($orderedUuids) !== $active->count()) {
            throw StoryRuntimeException::invalidReorder();
        }

        $byUuid = $active->keyBy('uuid');
        foreach ($orderedUuids as $uuid) {
            if (! $byUuid->has($uuid)) {
                throw StoryRuntimeException::invalidReorder();
            }
        }

        return DB::transaction(function () use ($orderedUuids, $byUuid, $workspace) {
            $sequence = 1;
            foreach ($orderedUuids as $uuid) {
                /** @var StoryReel $reel */
                $reel = $byUuid->get($uuid);
                $this->reels->update($reel, ['sequence' => $sequence]);
                $sequence++;
            }

            return $this->reels->listForWorkspace($workspace);
        });
    }
}
