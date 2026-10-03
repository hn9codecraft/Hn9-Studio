<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Story\Contracts\StoryPlanMaterializerInterface;
use App\Story\Contracts\StoryPlanRepositoryInterface;
use App\Story\Contracts\StoryPlanVersionRepositoryInterface;
use App\Story\Contracts\StoryReelRepositoryInterface;
use App\Story\Contracts\StorySceneRepositoryInterface;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Story\Enums\StoryPlanVersionStatus;
use App\Story\Enums\StoryReelStatus;
use App\Story\Enums\StorySceneStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Exceptions\StoryRuntimeException;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryReel;
use App\Story\Support\StorySceneTimingNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Converts a completed Story Plan Version into a runtime Reel + Scene snapshot set.
 * Does not rewrite existing runtime scenes when plans regenerate.
 */
final readonly class StoryPlanMaterializer implements StoryPlanMaterializerInterface
{
    public function __construct(
        private StoryWorkspaceServiceInterface $workspaces,
        private StoryPlanRepositoryInterface $plans,
        private StoryPlanVersionRepositoryInterface $versions,
        private StoryReelRepositoryInterface $reels,
        private StorySceneRepositoryInterface $scenes,
        private StorySceneTimingNormalizer $timing,
    ) {}

    public function materialize(Project $project, string $planUuid, string $versionUuid): array
    {
        $workspace = $this->workspaces->workspaceForProject($project);
        $plan = $this->plans->findByUuidForWorkspace($workspace, $planUuid);

        if ($plan === null) {
            throw StoryException::notFound('Story plan');
        }

        $version = $this->versions->findByUuidForPlan($plan, $versionUuid);

        if ($version === null) {
            throw StoryException::notFound('Story plan version');
        }

        if ($version->statusEnum() !== StoryPlanVersionStatus::Completed) {
            throw StoryRuntimeException::materializationFailed(
                'Only completed story plan versions can be materialized.',
            );
        }

        $existing = $this->reels->findBySourcePlanVersionId($workspace, (int) $version->id);
        if ($existing !== null) {
            return $this->existingResult($existing);
        }

        $planPayload = $version->plan;
        if (! is_array($planPayload)) {
            throw StoryRuntimeException::materializationFailed('Plan version has no structured plan payload.');
        }

        $sceneRows = $planPayload['scenes'] ?? null;
        if (! is_array($sceneRows) || $sceneRows === []) {
            throw StoryRuntimeException::materializationFailed('Plan version scenes are missing or empty.');
        }

        $validatedScenes = $this->validateAndSnapshotScenes($sceneRows);

        try {
            $reel = DB::transaction(function () use ($workspace, $plan, $version, $planPayload, $validatedScenes) {
                // The story plan row serialises every write to its scenes and production plans.
                StoryPlan::query()->whereKey($plan->id)->lockForUpdate()->first(['id']);
                $raced = $this->reels->findBySourcePlanVersionId($workspace, (int) $version->id);
                if ($raced !== null) {
                    return $raced;
                }

                $title = is_string($planPayload['title'] ?? null) && $planPayload['title'] !== ''
                    ? $planPayload['title']
                    : ($plan->title ?: 'Story Reel');

                $reel = $this->reels->create($workspace, [
                    'title' => $title,
                    'description' => is_string($planPayload['logline'] ?? null) ? $planPayload['logline'] : null,
                    'sequence' => $this->reels->nextSequence($workspace),
                    'status' => StoryReelStatus::Active->value,
                    'total_duration_seconds' => 0,
                    'source_plan_id' => $plan->id,
                    'source_plan_version_id' => $version->id,
                ]);

                $created = collect();
                foreach ($validatedScenes as $index => $snapshot) {
                    $scene = $this->scenes->create($reel, [
                        ...$snapshot,
                        'sequence' => $index + 1,
                        'status' => StorySceneStatus::Active->value,
                        'source_plan_version_id' => $version->id,
                    ]);
                    $created->push($scene);
                }

                $this->timing->normalizeReel($reel, $created);

                return $reel->refresh()->loadMissing([
                    'scenes',
                    'sourcePlan',
                    'sourcePlanVersion',
                    'workspace.project',
                ]);
            });
        } catch (StoryRuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw StoryRuntimeException::materializationFailed(
                'Materialization failed and was rolled back.',
                $e,
            );
        }

        if (! $reel->wasRecentlyCreated) {
            return $this->existingResult($reel);
        }

        return [
            'reel' => $reel,
            'created' => true,
            'scene_count' => $reel->scenes
                ->where('status', '!=', StorySceneStatus::Archived->value)
                ->count(),
        ];
    }

    /**
     * @return array{reel: StoryReel, created: false, scene_count: int}
     */
    private function existingResult(StoryReel $reel): array
    {
        return [
            'reel' => $reel->loadMissing(['scenes', 'sourcePlan', 'sourcePlanVersion', 'workspace.project']),
            'created' => false,
            'scene_count' => $reel->scenes
                ->where('status', '!=', StorySceneStatus::Archived->value)
                ->count(),
        ];
    }

    /**
     * @param  list<mixed>  $sceneRows
     * @return list<array<string, mixed>>
     */
    private function validateAndSnapshotScenes(array $sceneRows): array
    {
        $snapshots = [];

        foreach ($sceneRows as $index => $row) {
            if (! is_array($row)) {
                throw StoryRuntimeException::materializationFailed("Scene index {$index} is invalid.");
            }

            $duration = (int) ($row['duration_seconds'] ?? 0);
            $this->timing->assertValidDuration($duration);

            $story = $this->requiredString($row, 'story', $index);
            $visual = $this->requiredString($row, 'visual_prompt', $index);
            $motion = $this->requiredString($row, 'motion_prompt', $index);

            $continuity = $row['continuity'] ?? [];
            if (! is_array($continuity)) {
                throw StoryRuntimeException::materializationFailed(
                    "Scene index {$index} continuity must be an object.",
                );
            }

            $snapshots[] = [
                'title' => $this->optionalString($row, 'title'),
                'duration_seconds' => $duration,
                'start_second' => 0,
                'end_second' => $duration,
                'story' => $story,
                'characters' => $this->stringList($row['characters'] ?? []),
                'location' => $this->optionalString($row, 'location'),
                'dialogue' => $this->normalizeDialogue($row['dialogue'] ?? []),
                'narration' => $this->optionalString($row, 'narration'),
                'visual_prompt' => $visual,
                'motion_prompt' => $motion,
                'audio_direction' => $this->optionalString($row, 'audio_direction'),
                'continuity' => [
                    'previous_scene' => $this->nullableString($continuity['previous_scene'] ?? null),
                    'next_scene' => $this->nullableString($continuity['next_scene'] ?? null),
                    'character_state' => $this->nullableString($continuity['character_state'] ?? null),
                    'environment_state' => $this->nullableString($continuity['environment_state'] ?? null),
                ],
            ];
        }

        return $snapshots;
    }

    private function requiredString(array $row, string $key, int $index): string
    {
        $value = $row[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw StoryRuntimeException::materializationFailed(
                "Scene index {$index} is missing required field '{$key}'.",
            );
        }

        return trim($value);
    }

    private function optionalString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            } elseif (is_array($item) && isset($item['name']) && is_string($item['name'])) {
                $name = trim($item['name']);
                if ($name !== '') {
                    $out[] = $name;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>|string>
     */
    private function normalizeDialogue(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $line) {
            if (is_string($line) && trim($line) !== '') {
                $out[] = trim($line);
            } elseif (is_array($line)) {
                $out[] = $line;
            }
        }

        return $out;
    }
}
