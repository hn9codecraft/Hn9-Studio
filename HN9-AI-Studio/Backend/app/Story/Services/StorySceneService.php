<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Story\Contracts\StoryReelServiceInterface;
use App\Story\Contracts\StorySceneRepositoryInterface;
use App\Story\Contracts\StorySceneServiceInterface;
use App\Story\Enums\StorySceneStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Exceptions\StoryRuntimeException;
use App\Story\Models\StoryScene;
use App\Story\Support\StorySceneTimingNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class StorySceneService implements StorySceneServiceInterface
{
    public function __construct(
        private StoryReelServiceInterface $reels,
        private StorySceneRepositoryInterface $scenes,
        private StorySceneTimingNormalizer $timing,
    ) {}

    public function listForProjectReel(Project $project, string $reelUuid): Collection
    {
        $reel = $this->reels->getForProject($project, $reelUuid);

        return $this->scenes->listForReel($reel);
    }

    public function getForProjectReel(Project $project, string $reelUuid, string $sceneUuid): StoryScene
    {
        $reel = $this->reels->getForProject($project, $reelUuid);
        $scene = $this->scenes->findByUuidForReel($reel, $sceneUuid);

        if ($scene === null) {
            throw StoryException::notFound('Scene');
        }

        return $scene;
    }

    public function create(Project $project, string $reelUuid, array $attributes): StoryScene
    {
        $reel = $this->reels->getForProject($project, $reelUuid);

        if (! $reel->allowsEdit()) {
            throw StoryRuntimeException::archived('Reel');
        }

        $duration = (int) ($attributes['duration_seconds'] ?? StorySceneTimingNormalizer::DEFAULT_DURATION_SECONDS);
        $this->timing->assertValidDuration($duration);

        return DB::transaction(function () use ($reel, $attributes, $duration) {
            $active = $this->scenes->listForReel($reel);
            $insertAt = isset($attributes['sequence'])
                ? max(1, min((int) $attributes['sequence'], $active->count() + 1))
                : $active->count() + 1;

            $scene = $this->scenes->create($reel, [
                'title' => $attributes['title'] ?? null,
                'duration_seconds' => $duration,
                'sequence' => $insertAt,
                'start_second' => 0,
                'end_second' => $duration,
                'story' => $attributes['story'] ?? null,
                'characters' => $attributes['characters'] ?? [],
                'location' => $attributes['location'] ?? null,
                'dialogue' => $attributes['dialogue'] ?? [],
                'narration' => $attributes['narration'] ?? null,
                'visual_prompt' => $attributes['visual_prompt'] ?? null,
                'motion_prompt' => $attributes['motion_prompt'] ?? null,
                'audio_direction' => $attributes['audio_direction'] ?? null,
                'continuity' => $attributes['continuity'] ?? $this->emptyContinuity(),
                'status' => StorySceneStatus::Draft->value,
            ]);

            $ordered = $active->values();
            $ordered->splice($insertAt - 1, 0, [$scene]);
            $this->timing->normalizeReel($reel, $ordered);

            return $this->scenes->findByUuidForReel($reel->refresh(), $scene->uuid)
                ?? $scene->refresh();
        });
    }

    public function update(Project $project, string $reelUuid, string $sceneUuid, array $attributes): StoryScene
    {
        $scene = $this->getForProjectReel($project, $reelUuid, $sceneUuid);
        $reel = $scene->reel;

        if (! $reel->allowsEdit()) {
            throw StoryRuntimeException::archived('Reel');
        }

        if (! $scene->allowsEdit()) {
            throw StoryRuntimeException::archived('Scene');
        }

        $payload = array_intersect_key($attributes, array_flip([
            'title', 'story', 'characters', 'location', 'dialogue', 'narration',
            'visual_prompt', 'motion_prompt', 'audio_direction', 'continuity', 'duration_seconds',
        ]));

        if (isset($payload['duration_seconds'])) {
            $this->timing->assertValidDuration((int) $payload['duration_seconds']);
            $payload['duration_seconds'] = (int) $payload['duration_seconds'];
        }

        return DB::transaction(function () use ($scene, $reel, $payload) {
            $updated = $this->scenes->update($scene, $payload);
            $this->timing->normalizeReel($reel);

            return $this->scenes->findByUuidForReel($reel->refresh(), $updated->uuid)
                ?? $updated->refresh();
        });
    }

    public function duplicate(Project $project, string $reelUuid, string $sceneUuid): StoryScene
    {
        $source = $this->getForProjectReel($project, $reelUuid, $sceneUuid);
        $reel = $source->reel;

        if (! $reel->allowsEdit()) {
            throw StoryRuntimeException::archived('Reel');
        }

        return $this->create($project, $reelUuid, [
            'title' => ($source->title ? $source->title.' (copy)' : 'Scene copy'),
            'duration_seconds' => $source->duration_seconds,
            'sequence' => $source->sequence + 1,
            'story' => $source->story,
            'characters' => $source->characters ?? [],
            'location' => $source->location,
            'dialogue' => $source->dialogue ?? [],
            'narration' => $source->narration,
            'visual_prompt' => $source->visual_prompt,
            'motion_prompt' => $source->motion_prompt,
            'audio_direction' => $source->audio_direction,
            'continuity' => $source->continuity ?? $this->emptyContinuity(),
        ]);
    }

    public function archive(Project $project, string $reelUuid, string $sceneUuid): StoryScene
    {
        $scene = $this->getForProjectReel($project, $reelUuid, $sceneUuid);
        $reel = $scene->reel;

        if (! $reel->allowsEdit()) {
            throw StoryRuntimeException::archived('Reel');
        }

        if ($scene->statusEnum() === StorySceneStatus::Archived) {
            return $scene;
        }

        return DB::transaction(function () use ($scene, $reel) {
            $archived = $this->scenes->update($scene, [
                'status' => StorySceneStatus::Archived->value,
            ]);
            $this->timing->normalizeReel($reel);

            return $archived->refresh();
        });
    }

    public function reorder(Project $project, string $reelUuid, array $orderedUuids): Collection
    {
        $reel = $this->reels->getForProject($project, $reelUuid);

        if (! $reel->allowsEdit()) {
            throw StoryRuntimeException::archived('Reel');
        }

        $active = $this->scenes->listForReel($reel);

        if (count($orderedUuids) !== $active->count()) {
            throw StoryRuntimeException::invalidReorder();
        }

        $ordered = $this->scenes->findActiveByUuids($reel, $orderedUuids);

        if ($ordered->count() !== $active->count()) {
            throw StoryRuntimeException::invalidReorder();
        }

        return DB::transaction(function () use ($reel, $ordered) {
            $this->timing->normalizeReel($reel, $ordered);

            return $this->scenes->listForReel($reel->refresh());
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyContinuity(): array
    {
        return [
            'previous_scene' => null,
            'next_scene' => null,
            'character_state' => null,
            'environment_state' => null,
        ];
    }
}
