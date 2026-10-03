<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Story\Enums\StoryReviewStatus;
use App\Story\Enums\StoryTimelineTransitionType;
use App\Story\Exceptions\StoryException;
use App\Story\Models\StoryReel;
use App\Story\Models\StorySceneAudio;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryTimeline;
use App\Story\Models\StoryTimelineClip;
use App\Story\Models\StoryTimelineTransition;
use App\Story\Models\StoryVideoGenerationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Timeline data only. Clips reference private files and never call a provider.
 */
final class StoryTimelineService
{
    /**
     * @return array<string, mixed>
     */
    public function show(Project $project, string $reelUuid): array
    {
        $reel = $this->reel($project, $reelUuid);
        $timeline = StoryTimeline::query()->where('story_reel_id', $reel->id)->first();

        return $this->payload($timeline);
    }

    /**
     * @return array<string, mixed>
     */
    public function place(Project $project, string $reelUuid, string $kind, string $sourceUuid): array
    {
        $reel = $this->reel($project, $reelUuid);
        $source = $this->source($project, $kind, $sourceUuid);

        return DB::transaction(function () use ($reel, $source): array {
            $timeline = $this->timeline($reel);
            $position = (int) StoryTimelineClip::query()->where('story_timeline_id', $timeline->id)->max('position') + 1;
            StoryTimelineClip::query()->create([
                'story_timeline_id' => $timeline->id,
                'position' => $position,
                'media_kind' => $source['kind'],
                'story_scene_version_id' => $source['version_id'],
                'story_scene_audio_id' => $source['audio_id'],
                'disk' => $source['disk'],
                'path' => $source['path'],
                'in_ms' => 0,
                'out_ms' => $source['duration_ms'],
            ]);

            return $this->payload($timeline->fresh());
        });
    }

    /**
     * Puts an approved scene video on its reel's timeline: swaps the scene's existing clip
     * to the new version, or appends one. Versions without a stored video are skipped.
     */
    public function placeApprovedSceneVersion(Project $project, StorySceneVersion $version): void
    {
        $version->loadMissing('scene.reel');
        $reel = $version->scene?->reel;
        if (! $reel instanceof StoryReel) {
            return;
        }

        try {
            $this->videoSource($project, $version->uuid);
        } catch (StoryException) {
            return;
        }

        $timeline = StoryTimeline::query()->where('story_reel_id', $reel->id)->first();
        $existing = $timeline === null ? null : StoryTimelineClip::query()
            ->where('story_timeline_id', $timeline->id)
            ->where('media_kind', 'video')
            ->whereHas('sceneVersion', static function ($query) use ($version): void {
                $query->where('story_scene_id', $version->story_scene_id);
            })
            ->orderBy('position')
            ->first();

        if ($existing instanceof StoryTimelineClip) {
            if ((int) $existing->story_scene_version_id !== (int) $version->id) {
                $this->replace($project, $reel->uuid, $existing->uuid, $version->uuid);
            }

            return;
        }

        $this->place($project, $reel->uuid, 'video', $version->uuid);
    }

    /**
     * Puts an approved scene sound on its reel's timeline: swaps the clip holding the same
     * scene and role to the new version, or appends one. Unapproved or unstored sounds are skipped.
     */
    public function placeApprovedSceneAudio(Project $project, StorySceneAudio $audio): void
    {
        $audio->loadMissing('scene.reel');
        $reel = $audio->scene?->reel;
        if (! $reel instanceof StoryReel) {
            return;
        }

        try {
            $this->audioSource($project, $audio->uuid);
        } catch (StoryException) {
            return;
        }

        $timeline = StoryTimeline::query()->where('story_reel_id', $reel->id)->first();
        $existing = $timeline === null ? null : StoryTimelineClip::query()
            ->where('story_timeline_id', $timeline->id)
            ->where('media_kind', 'audio')
            ->whereHas('sceneAudio', static function ($query) use ($audio): void {
                $query->where('story_scene_id', $audio->story_scene_id)
                    ->where('role', $audio->role);
            })
            ->orderBy('position')
            ->first();

        if ($existing instanceof StoryTimelineClip) {
            if ((int) $existing->story_scene_audio_id !== (int) $audio->id) {
                $this->replace($project, $reel->uuid, $existing->uuid, $audio->uuid);
            }

            return;
        }

        $anchor = $timeline === null ? null : $this->sceneTail($timeline, (int) $audio->story_scene_id);
        if (! $anchor instanceof StoryTimelineClip) {
            $this->place($project, $reel->uuid, 'audio', $audio->uuid);

            return;
        }

        $source = $this->audioSource($project, $audio->uuid);
        DB::transaction(function () use ($timeline, $anchor, $source): void {
            $this->shiftAfter($timeline, $anchor->position);
            $clip = StoryTimelineClip::query()->create([
                'story_timeline_id' => $timeline->id,
                'position' => $anchor->position + 1,
                'media_kind' => 'audio',
                'story_scene_version_id' => null,
                'story_scene_audio_id' => $source['audio_id'],
                'disk' => $source['disk'],
                'path' => $source['path'],
                'in_ms' => 0,
                'out_ms' => $source['duration_ms'],
            ]);
            // Render keys transitions by their target clip, so moving the start keeps the effect.
            StoryTimelineTransition::query()
                ->where('story_timeline_id', $timeline->id)
                ->where('from_clip_id', $anchor->id)
                ->update(['from_clip_id' => $clip->id]);
        });
    }

    /**
     * Last clip of a scene's run: its video clip followed by any sound clips laid over it.
     */
    private function sceneTail(StoryTimeline $timeline, int $sceneId): ?StoryTimelineClip
    {
        $clips = $this->clips($timeline);
        $tail = null;
        foreach ($clips as $clip) {
            $clip->loadMissing('sceneVersion', 'sceneAudio');
            if ($tail === null) {
                if ($clip->media_kind === 'video' && (int) $clip->sceneVersion?->story_scene_id === $sceneId) {
                    $tail = $clip;
                }

                continue;
            }
            if ($clip->media_kind !== 'audio') {
                break;
            }
            $tail = $clip;
        }

        return $tail;
    }

    /**
     * @param  list<string>  $orderedIds
     * @return array<string, mixed>
     */
    public function reorder(Project $project, string $reelUuid, array $orderedIds): array
    {
        $timeline = $this->requireTimeline($project, $reelUuid);

        return DB::transaction(function () use ($timeline, $orderedIds): array {
            $clips = $this->clips($timeline);
            $byUuid = [];
            foreach ($clips as $clip) {
                $byUuid[$clip->uuid] = $clip;
            }
            if (count($orderedIds) !== count($clips) || count(array_unique($orderedIds)) !== count($clips)) {
                throw $this->invalid('Timeline order must include every clip once.');
            }
            foreach ($orderedIds as $index => $uuid) {
                if (! isset($byUuid[$uuid])) {
                    throw $this->invalid('Timeline order must include every clip once.');
                }
                $byUuid[$uuid]->forceFill(['position' => $index + 1])->save();
            }
            $this->dropBrokenTransitions($timeline);

            return $this->payload($timeline->fresh());
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function trim(Project $project, string $reelUuid, string $clipUuid, int $inMs, int $outMs): array
    {
        $timeline = $this->requireTimeline($project, $reelUuid);
        $clip = $this->clip($timeline, $clipUuid);
        if ($inMs < 0 || $outMs <= $inMs) {
            throw $this->invalid('Trim points must keep a positive duration.');
        }
        $clip->forceFill(['in_ms' => $inMs, 'out_ms' => $outMs])->save();

        return $this->payload($timeline->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function split(Project $project, string $reelUuid, string $clipUuid, int $atMs): array
    {
        $timeline = $this->requireTimeline($project, $reelUuid);

        return DB::transaction(function () use ($timeline, $clipUuid, $atMs): array {
            $clip = $this->clip($timeline, $clipUuid);
            if ($atMs <= $clip->in_ms || $atMs >= $clip->out_ms) {
                throw $this->invalid('Split point must sit inside the clip.');
            }
            $originalOut = $clip->out_ms;
            $clip->forceFill(['out_ms' => $atMs])->save();
            $this->shiftAfter($timeline, $clip->position);
            StoryTimelineClip::query()->create([
                'story_timeline_id' => $timeline->id,
                'position' => $clip->position + 1,
                'media_kind' => $clip->media_kind,
                'story_scene_version_id' => $clip->story_scene_version_id,
                'story_scene_audio_id' => $clip->story_scene_audio_id,
                'disk' => $clip->disk,
                'path' => $clip->path,
                'in_ms' => $atMs,
                'out_ms' => $originalOut,
            ]);
            $this->dropBrokenTransitions($timeline);

            return $this->payload($timeline->fresh());
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function replace(Project $project, string $reelUuid, string $clipUuid, string $sourceUuid): array
    {
        $timeline = $this->requireTimeline($project, $reelUuid);
        $clip = $this->clip($timeline, $clipUuid);
        $source = $this->source($project, $clip->media_kind, $sourceUuid);
        $clip->forceFill([
            'story_scene_version_id' => $source['version_id'],
            'story_scene_audio_id' => $source['audio_id'],
            'disk' => $source['disk'],
            'path' => $source['path'],
        ])->save();

        return $this->payload($timeline->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function duplicate(Project $project, string $reelUuid, string $clipUuid): array
    {
        $timeline = $this->requireTimeline($project, $reelUuid);

        return DB::transaction(function () use ($timeline, $clipUuid): array {
            $clip = $this->clip($timeline, $clipUuid);
            $this->shiftAfter($timeline, $clip->position);
            StoryTimelineClip::query()->create([
                'story_timeline_id' => $timeline->id,
                'position' => $clip->position + 1,
                'media_kind' => $clip->media_kind,
                'story_scene_version_id' => $clip->story_scene_version_id,
                'story_scene_audio_id' => $clip->story_scene_audio_id,
                'disk' => $clip->disk,
                'path' => $clip->path,
                'in_ms' => $clip->in_ms,
                'out_ms' => $clip->out_ms,
            ]);

            return $this->payload($timeline->fresh());
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(Project $project, string $reelUuid, string $clipUuid): array
    {
        $timeline = $this->requireTimeline($project, $reelUuid);

        return DB::transaction(function () use ($timeline, $clipUuid): array {
            $clip = $this->clip($timeline, $clipUuid);
            StoryTimelineTransition::query()
                ->where('story_timeline_id', $timeline->id)
                ->where(function ($query) use ($clip): void {
                    $query->where('from_clip_id', $clip->id)->orWhere('to_clip_id', $clip->id);
                })
                ->delete();
            $clip->delete();
            $this->renumber($timeline);

            return $this->payload($timeline->fresh());
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function transition(
        Project $project,
        string $reelUuid,
        string $fromUuid,
        string $toUuid,
        string $type,
        int $durationMs,
    ): array {
        $timeline = $this->requireTimeline($project, $reelUuid);
        $transitionType = StoryTimelineTransitionType::tryFrom($type);
        if ($transitionType === null || $durationMs < 0) {
            throw $this->invalid('Transition type must be cut, dissolve, or fade.');
        }
        $from = $this->clip($timeline, $fromUuid);
        $to = $this->clip($timeline, $toUuid);
        if ($to->position !== $from->position + 1) {
            throw $this->invalid('A transition can only join adjacent clips.');
        }

        StoryTimelineTransition::query()->updateOrCreate(
            ['from_clip_id' => $from->id],
            [
                'story_timeline_id' => $timeline->id,
                'to_clip_id' => $to->id,
                'type' => $transitionType->value,
                'duration_ms' => $durationMs,
            ],
        );

        return $this->payload($timeline->fresh());
    }

    private function reel(Project $project, string $reelUuid): StoryReel
    {
        $reel = StoryReel::query()
            ->where('uuid', $reelUuid)
            ->whereHas('workspace', static function ($query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->first();
        if (! $reel instanceof StoryReel) {
            throw StoryException::notFound('Reel');
        }

        return $reel;
    }

    private function timeline(StoryReel $reel): StoryTimeline
    {
        return StoryTimeline::query()->firstOrCreate(['story_reel_id' => $reel->id]);
    }

    private function requireTimeline(Project $project, string $reelUuid): StoryTimeline
    {
        $reel = $this->reel($project, $reelUuid);
        $timeline = StoryTimeline::query()->where('story_reel_id', $reel->id)->first();
        if (! $timeline instanceof StoryTimeline) {
            throw StoryException::notFound('Timeline');
        }

        return $timeline;
    }

    private function clip(StoryTimeline $timeline, string $clipUuid): StoryTimelineClip
    {
        $clip = StoryTimelineClip::query()
            ->where('story_timeline_id', $timeline->id)
            ->where('uuid', $clipUuid)
            ->first();
        if (! $clip instanceof StoryTimelineClip) {
            throw StoryException::notFound('Timeline clip');
        }

        return $clip;
    }

    /**
     * @return array{kind: string, version_id: int|null, audio_id: int|null, disk: string, path: string, duration_ms: int}
     */
    private function source(Project $project, string $kind, string $sourceUuid): array
    {
        if ($kind === 'video') {
            return $this->videoSource($project, $sourceUuid);
        }
        if ($kind === 'audio') {
            return $this->audioSource($project, $sourceUuid);
        }

        throw $this->invalid('A clip must reference stored video or audio.');
    }

    /**
     * @return array{kind: string, version_id: int, audio_id: null, disk: string, path: string, duration_ms: int}
     */
    private function videoSource(Project $project, string $versionUuid): array
    {
        $version = StorySceneVersion::query()
            ->where('uuid', $versionUuid)
            ->whereHas('scene.reel.workspace', static function ($query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->first();
        if (! $version instanceof StorySceneVersion) {
            throw $this->invalid('The scene video does not belong to this project.');
        }

        $job = StoryVideoGenerationJob::query()
            ->where('story_scene_id', $version->story_scene_id)
            ->orderByDesc('id')
            ->get()
            ->first(static function (StoryVideoGenerationJob $candidate) use ($version): bool {
                $storage = $candidate->provider_metadata['storage'] ?? null;

                return ($candidate->provider_metadata['version_id'] ?? null) === $version->uuid
                    && is_array($storage)
                    && ($storage['disk'] ?? null) === 'videos'
                    && is_string($storage['path'] ?? null)
                    && $storage['path'] !== ''
                    && ! str_contains($storage['path'], '..')
                    && ! str_contains($storage['path'], '://');
            });
        if (! $job instanceof StoryVideoGenerationJob) {
            throw $this->invalid('A stored scene video is required.');
        }
        $path = (string) $job->provider_metadata['storage']['path'];
        if (! Storage::disk('videos')->exists($path)) {
            throw $this->invalid('A stored scene video is required.');
        }

        return [
            'kind' => 'video',
            'version_id' => $version->id,
            'audio_id' => null,
            'disk' => 'videos',
            'path' => $path,
            'duration_ms' => 8000,
        ];
    }

    /**
     * @return array{kind: string, version_id: null, audio_id: int, disk: string, path: string, duration_ms: int}
     */
    private function audioSource(Project $project, string $audioUuid): array
    {
        $audio = StorySceneAudio::query()
            ->where('uuid', $audioUuid)
            ->whereHas('scene.reel.workspace', static function ($query) use ($project): void {
                $query->where('project_id', $project->id);
            })
            ->first();
        if (! $audio instanceof StorySceneAudio
            || $audio->disk !== 'voice'
            || ! is_string($audio->path)
            || $audio->path === ''
            || str_contains($audio->path, '..')
            || str_contains($audio->path, '://')
            || ! Storage::disk('voice')->exists($audio->path)) {
            throw $this->invalid('The scene audio does not belong to this project.');
        }
        if ($audio->reviewStatusEnum() !== StoryReviewStatus::Approved) {
            throw $this->invalid('Approve this sound before adding it to the timeline.');
        }

        return [
            'kind' => 'audio',
            'version_id' => null,
            'audio_id' => $audio->id,
            'disk' => 'voice',
            'path' => $audio->path,
            'duration_ms' => 8000,
        ];
    }

    /**
     * @return list<StoryTimelineClip>
     */
    private function clips(StoryTimeline $timeline): array
    {
        return StoryTimelineClip::query()
            ->where('story_timeline_id', $timeline->id)
            ->orderBy('position')
            ->get()
            ->all();
    }

    private function shiftAfter(StoryTimeline $timeline, int $position): void
    {
        StoryTimelineClip::query()
            ->where('story_timeline_id', $timeline->id)
            ->where('position', '>', $position)
            ->orderByDesc('position')
            ->get()
            ->each(static function (StoryTimelineClip $clip): void {
                $clip->forceFill(['position' => $clip->position + 1])->save();
            });
    }

    private function renumber(StoryTimeline $timeline): void
    {
        $position = 1;
        foreach ($this->clips($timeline) as $clip) {
            $clip->forceFill(['position' => $position])->save();
            $position++;
        }
    }

    private function dropBrokenTransitions(StoryTimeline $timeline): void
    {
        $clips = $this->clips($timeline);
        $next = [];
        foreach ($clips as $index => $clip) {
            $next[$clip->id] = $clips[$index + 1]->id ?? null;
        }
        StoryTimelineTransition::query()
            ->where('story_timeline_id', $timeline->id)
            ->get()
            ->each(static function (StoryTimelineTransition $transition) use ($next): void {
                if (($next[$transition->from_clip_id] ?? null) !== $transition->to_clip_id) {
                    $transition->delete();
                }
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?StoryTimeline $timeline): array
    {
        if (! $timeline instanceof StoryTimeline) {
            return ['id' => null, 'clips' => [], 'transitions' => []];
        }

        $clips = $this->clips($timeline);
        $clipUuids = [];
        foreach ($clips as $clip) {
            $clipUuids[$clip->id] = $clip->uuid;
        }

        return [
            'id' => $timeline->uuid,
            'output_url' => null,
            'clips' => array_map(static function (StoryTimelineClip $clip): array {
                $scene = $clip->sceneVersion?->scene ?? $clip->sceneAudio?->scene;

                return [
                    'id' => $clip->uuid,
                    'position' => $clip->position,
                    'media_kind' => $clip->media_kind,
                    'source_version_id' => $clip->sceneVersion?->uuid,
                    'source_version_number' => $clip->sceneVersion?->version,
                    'source_audio_id' => $clip->sceneAudio?->uuid,
                    'scene_id' => $scene?->uuid,
                    'scene_sequence' => $scene?->sequence,
                    'scene_title' => $scene?->title,
                    'disk' => $clip->disk,
                    'path' => $clip->path,
                    'in_ms' => $clip->in_ms,
                    'out_ms' => $clip->out_ms,
                    'output_url' => null,
                ];
            }, $this->loadedClips($clips)),
            'transitions' => StoryTimelineTransition::query()
                ->where('story_timeline_id', $timeline->id)
                ->get()
                ->map(static function (StoryTimelineTransition $transition) use ($clipUuids): array {
                    return [
                        'id' => $transition->uuid,
                        'from_clip_id' => $clipUuids[$transition->from_clip_id] ?? null,
                        'to_clip_id' => $clipUuids[$transition->to_clip_id] ?? null,
                        'type' => $transition->type,
                        'duration_ms' => $transition->duration_ms,
                    ];
                })
                ->all(),
        ];
    }

    /**
     * @param  list<StoryTimelineClip>  $clips
     * @return list<StoryTimelineClip>
     */
    private function loadedClips(array $clips): array
    {
        foreach ($clips as $clip) {
            $clip->loadMissing('sceneVersion.scene', 'sceneAudio.scene');
        }

        return $clips;
    }

    private function invalid(string $message): StoryException
    {
        return new StoryException($message, 'story_timeline_invalid', 422);
    }
}
