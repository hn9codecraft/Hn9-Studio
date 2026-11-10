<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Contracts\StorySceneServiceInterface;
use App\Story\Enums\StoryReviewStatus;
use App\Story\Enums\StorySceneStatus;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Exceptions\StoryProductionPlanException;
use App\Story\Exceptions\StoryVoiceTimingException;
use App\Story\Media\StoryMediaToolkit;
use App\Story\Models\StoryAudioTiming;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionPlanScene;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneAudio;
use App\Story\Support\StoryGenerationUnitCalculator;
use App\Story\Support\StorySceneTimingNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Measures speech and proposes scene lengths. Apply writes lengths through
 * StorySceneService and one new production-plan revision through revise().
 * Nothing is generated.
 */
final class StoryVoiceTimingService
{
    public function __construct(
        private StoryProductionPlanServiceInterface $plans,
        private StorySceneServiceInterface $scenes,
        private StoryMediaToolkit $media,
        private StoryGenerationUnitCalculator $calculator,
        private StoryCostEstimator $costs,
        private ActivityLoggerInterface $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function preview(Project $project, string $planUuid, array $options = []): array
    {
        $plan = $this->plan($project, $planUuid);
        $settings = $this->settings($options);

        return $this->build($project, $plan, $settings, persist: true);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function apply(Project $project, string $planUuid, array $options, User $actor): array
    {
        try {
            $plan = $this->plans->getForProject($project, $planUuid);
        } catch (StoryException) {
            throw StoryException::notFound('Production plan');
        }
        $settings = $this->settings($options);
        if (! $this->media->available()) {
            throw StoryVoiceTimingException::mediaUnavailable();
        }

        $workspaceId = (int) $plan->story_workspace_id;
        $cached = $this->remembered($workspaceId, $options);
        if ($cached !== null) {
            return $cached;
        }

        $lock = Cache::lock('story-voice-timing:'.$workspaceId, 60);
        if (! $lock->get()) {
            throw StoryVoiceTimingException::inProgress();
        }

        try {
            $again = $this->remembered($workspaceId, $options);
            if ($again !== null) {
                return $again;
            }
            if (! $plan->isCurrent()) {
                throw StoryProductionPlanException::notCurrent();
            }
            $plan->loadMissing('reel');

            $built = $this->build($project, $plan, $settings, persist: true);
            $changed = array_values(array_filter($built['scenes'], static fn (array $scene): bool => $scene['changed'] === true));
            if ($changed === []) {
                $result = ['changed' => false, 'scenes' => $built['scenes'], 'regeneration_estimate' => $built['regeneration_estimate']];
                $this->remember($workspaceId, $options, $result);

                return $result;
            }

            if ($built['scenes_with_unit_versions'] > 0 && empty($options['accept_regeneration'])) {
                throw StoryVoiceTimingException::requiresRegeneration(
                    (int) $built['scenes_with_unit_versions'],
                    $built['regeneration_estimate'],
                );
            }

            $reelUuid = (string) $plan->reel->uuid;
            $previousUuid = $plan->uuid;
            $previousRevision = (int) $plan->revision;
            $before = [];
            foreach ($changed as $scene) {
                $before[] = ['scene_uuid' => $scene['scene_uuid'], 'before' => $scene['current_seconds'], 'after' => $scene['proposed_seconds']];
            }

            $revised = DB::transaction(function () use ($project, $planUuid, $actor, $reelUuid, $changed): array {
                foreach ($changed as $scene) {
                    $this->scenes->update($project, $reelUuid, $scene['scene_uuid'], [
                        'duration_seconds' => (int) $scene['proposed_seconds'],
                    ]);
                }

                return $this->plans->revise($project, $planUuid, null, $actor);
            });

            $next = $revised['plan'];
            $this->activity->log('story.voice_timing.applied', $next, $actor, 'Scene lengths matched to the voiceover', [
                'plan_uuid' => $previousUuid,
                'from_revision' => $previousRevision,
                'to_revision' => (int) $next->revision,
                'scenes' => $before,
            ]);

            $result = [
                'changed' => true,
                'production_plan' => [
                    'uuid' => $next->uuid,
                    'revision' => (int) $next->revision,
                    'total_duration_seconds' => (int) $next->total_duration_seconds,
                ],
                'previous_plan' => ['uuid' => $previousUuid, 'revision' => $previousRevision],
                'scenes' => $built['scenes'],
                'timeline_stale' => true,
                'regeneration_estimate' => $built['regeneration_estimate'],
            ];
            $this->remember($workspaceId, $options, $result);

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function build(Project $project, StoryProductionPlan $plan, array $settings, bool $persist): array
    {
        $measurable = $this->media->available();
        $plan->loadMissing(['reel', 'storyPlan', 'scenes.scene', 'scenes.units.versions']);
        $only = $settings['scene_uuids'];
        $rows = [];
        $totalBefore = 0;
        $totalAfter = 0;
        $withVersions = 0;
        $estimateUnits = [];

        foreach ($plan->scenes as $planScene) {
            $scene = $planScene->scene;
            if (! $scene instanceof StoryScene || $scene->statusEnum() === StorySceneStatus::Archived) {
                continue;
            }
            $current = (int) $scene->duration_seconds;
            $included = $only === null || in_array($scene->uuid, $only, true);
            $speech = $included ? $this->speech($scene) : ['audios' => [], 'warnings' => []];
            $warnings = $speech['warnings'];
            $audio = null;
            $voiceSeconds = null;
            $source = null;
            $proposed = $current;
            $bestMs = -1;
            foreach ($speech['audios'] as $candidate) {
                if (! $measurable) {
                    $warnings[] = 'voice_not_ready';
                    break;
                }
                $measured = $this->measure($candidate, $persist);
                if ($measured['duration_ms'] > $bestMs) {
                    $bestMs = $measured['duration_ms'];
                    $audio = $candidate;
                    $voiceSeconds = round($measured['duration_ms'] / 1000, 3);
                    $source = $measured['source'];
                }
            }
            if ($audio instanceof StorySceneAudio && $bestMs >= 0) {
                [$proposed, $extra] = $this->propose($bestMs, $settings);
                $warnings = [...$warnings, ...$extra];
            }
            $changed = $included && $proposed !== $current && $audio instanceof StorySceneAudio && $measurable;
            if (! $changed) {
                $proposed = $current;
            }
            $hasVersions = $planScene->units->contains(static fn (StoryProductionUnit $unit): bool => $unit->versions->isNotEmpty());
            if ($changed && $hasVersions) {
                $withVersions++;
                foreach ($planScene->units as $unit) {
                    if ($unit->versions->isNotEmpty()) {
                        $estimateUnits[] = $unit;
                    }
                }
            }
            $rows[] = [
                'scene_uuid' => $scene->uuid,
                'sequence' => (int) $planScene->sequence,
                'title' => $scene->title,
                'current_seconds' => $current,
                'voice_seconds' => $voiceSeconds,
                'voice_role' => $audio instanceof StorySceneAudio ? $audio->role : null,
                'voice_audio_uuid' => $audio instanceof StorySceneAudio ? $audio->uuid : null,
                'timing_source' => $source,
                'proposed_seconds' => $proposed,
                'changed' => $changed,
                'units_before' => $this->units($current),
                'units_after' => $this->units($proposed),
                'has_unit_versions' => $hasVersions,
                'warnings' => array_values(array_unique($warnings)),
            ];
            $totalBefore += $current;
            $totalAfter += $proposed;
        }

        $requested = (int) ($plan->storyPlan instanceof StoryPlan ? $plan->storyPlan->requested_duration_seconds : 0);
        $drift = false;
        if ($requested > 0) {
            $drift = abs($totalAfter - $requested) / $requested * 100 > (float) config('story_voice_timing.total_drift_warning_percent', 15);
        }

        return [
            'measurable' => $measurable,
            'message' => $measurable ? null : 'FFmpeg/ffprobe not found',
            'scenes' => $rows,
            'total_before' => $totalBefore,
            'total_after' => $totalAfter,
            'requested_duration_seconds' => $requested,
            'drift_warning' => $drift,
            'scenes_with_unit_versions' => $withVersions,
            'regeneration_estimate' => $this->estimate($project, $plan, $estimateUnits),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{round_to: int, lead_in_ms: int, tail_ms: int, scene_uuids: list<string>|null}
     */
    private function settings(array $options): array
    {
        $allowed = array_map('intval', (array) config('story_voice_timing.round_to_options', [1, 5]));
        $round = array_key_exists('round_to', $options) && $options['round_to'] !== null
            ? (int) $options['round_to']
            : (int) config('story_voice_timing.default_round_to', 1);
        if (! in_array($round, $allowed, true)) {
            throw new StoryException('Round to 1 or 5 seconds.', 'story_voice_timing_invalid', 422);
        }
        $lead = array_key_exists('lead_in_ms', $options) && $options['lead_in_ms'] !== null
            ? (int) $options['lead_in_ms']
            : (int) config('story_voice_timing.lead_in_ms', 300);
        $tail = array_key_exists('tail_ms', $options) && $options['tail_ms'] !== null
            ? (int) $options['tail_ms']
            : (int) config('story_voice_timing.tail_ms', 500);
        foreach (['lead_in_ms' => $lead, 'tail_ms' => $tail] as $name => $value) {
            if ($value < 0 || $value > 3000) {
                throw new StoryException($name.' must be between 0 and 3000.', 'story_voice_timing_invalid', 422);
            }
        }
        $uuids = null;
        if (isset($options['scene_uuids']) && is_array($options['scene_uuids'])) {
            $uuids = array_values(array_filter($options['scene_uuids'], static fn ($uuid): bool => is_string($uuid) && $uuid !== ''));
        }

        return ['round_to' => $round, 'lead_in_ms' => $lead, 'tail_ms' => $tail, 'scene_uuids' => $uuids];
    }

    /**
     * @param  array{round_to: int, lead_in_ms: int, tail_ms: int}  $settings
     * @return array{0: int, 1: list<string>}
     */
    private function propose(int $voiceMs, array $settings): array
    {
        $warnings = [];
        $round = max(1, $settings['round_to']);
        $padded = $voiceMs + $settings['lead_in_ms'] + $settings['tail_ms'];
        $proposed = (int) (ceil(($padded / 1000) / $round) * $round);
        $voiceSafe = (int) ceil($voiceMs / 1000);
        if ($proposed * 1000 < $voiceMs) {
            $proposed = (int) (ceil(($voiceMs / 1000) / $round) * $round);
        }
        $proposed = max($proposed, $voiceSafe);
        $min = max((int) config('story_voice_timing.min_scene_seconds', 3), StorySceneTimingNormalizer::MIN_DURATION_SECONDS);
        $max = min((int) config('story_voice_timing.max_scene_seconds', 300), StorySceneTimingNormalizer::MAX_DURATION_SECONDS);
        if ($proposed < $min) {
            $proposed = $min;
        }
        if ($proposed > $max) {
            if ($voiceSafe > $max) {
                $proposed = $voiceSafe;
                $warnings[] = 'voice_longer_than_max';
            } else {
                $proposed = $max;
            }
        }
        if ($proposed * 1000 < $voiceMs) {
            $proposed = $voiceSafe;
            $warnings[] = 'voice_longer_than_max';
        }

        return [$proposed, $warnings];
    }

    /**
     * @return array{audios: list<StorySceneAudio>, warnings: list<string>}
     */
    private function speech(StoryScene $scene): array
    {
        $chosen = [];
        $warnings = [];
        $roles = (array) config('story_voice_timing.speech_roles', []);
        $audios = StorySceneAudio::query()->where('story_scene_id', $scene->id)->orderByDesc('id')->get();
        foreach ($roles as $role) {
            $rows = $audios->filter(static fn (StorySceneAudio $audio): bool => $audio->role === $role)->values();
            $choice = $this->pick($rows);
            if (! $choice instanceof StorySceneAudio) {
                if ($rows->contains(fn (StorySceneAudio $audio): bool => $this->busy($audio))) {
                    $warnings[] = 'voice_not_ready';
                }

                continue;
            }
            $chosen[] = $choice;
        }

        return ['audios' => $chosen, 'warnings' => $warnings];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, StorySceneAudio>  $rows
     */
    private function pick($rows): ?StorySceneAudio
    {
        $ready = $rows->filter(fn (StorySceneAudio $audio): bool => $this->readyFile($audio));
        $selected = $ready->first(static fn (StorySceneAudio $audio): bool => $audio->selected_at !== null);
        if ($selected instanceof StorySceneAudio) {
            return $selected;
        }
        $approved = $ready->first(static fn (StorySceneAudio $audio): bool => $audio->reviewStatusEnum() === StoryReviewStatus::Approved);

        return $approved instanceof StorySceneAudio
            ? $approved
            : $ready->first(static fn (StorySceneAudio $audio): bool => $audio->statusEnum() === StoryVideoJobStatus::Completed);
    }

    private function readyFile(StorySceneAudio $audio): bool
    {
        return $audio->statusEnum() === StoryVideoJobStatus::Completed && $this->safePath($audio->disk, $audio->path) && Storage::disk((string) $audio->disk)->exists((string) $audio->path);
    }

    private function busy(StorySceneAudio $audio): bool
    {
        return in_array($audio->statusEnum(), [StoryVideoJobStatus::Queued, StoryVideoJobStatus::Submitted, StoryVideoJobStatus::Processing], true);
    }

    /**
     * @return array{duration_ms: int, source: string}
     */
    private function measure(StorySceneAudio $audio, bool $persist): array
    {
        $cached = StoryAudioTiming::query()->where('story_scene_audio_id', $audio->id)->first();
        if ($cached instanceof StoryAudioTiming && (string) $cached->checksum === (string) $audio->checksum) {
            return ['duration_ms' => (int) $cached->duration_ms, 'source' => 'cached'];
        }
        if (! $this->safePath($audio->disk, $audio->path)) {
            throw new StoryException('A voiceover file could not be read.', 'story_voice_timing_invalid', 422);
        }
        $probe = $this->media->probe(Storage::disk((string) $audio->disk)->path((string) $audio->path));
        $duration = max(0, (int) round(((float) ($probe['duration'] ?? 0)) * 1000));
        $sidecar = $this->sidecar($audio);
        if ($persist) {
            StoryAudioTiming::query()->updateOrCreate(
                ['story_scene_audio_id' => $audio->id],
                [
                    'checksum' => $audio->checksum,
                    'duration_ms' => $duration,
                    'alignment_source' => $sidecar === null ? 'none' : 'exact',
                    'alignment_disk' => $sidecar['disk'] ?? null,
                    'alignment_path' => $sidecar['path'] ?? null,
                    'measured_at' => now(),
                ],
            );
        }

        return ['duration_ms' => $duration, 'source' => 'measured'];
    }

    /**
     * @return array{disk: string, path: string}|null
     */
    private function sidecar(StorySceneAudio $audio): ?array
    {
        $audio->loadMissing('job');
        $operation = $audio->job?->operation_id;
        if (! is_string($operation) || $operation === '') {
            return null;
        }
        $path = 'alignments/'.$operation.'.json';
        if (! $this->safePath('voice', $path) || ! Storage::disk('voice')->exists($path)) {
            return null;
        }

        return ['disk' => 'voice', 'path' => $path];
    }

    /**
     * @return list<array{sequence: int, duration_seconds: int}>
     */
    private function units(int $seconds): array
    {
        if ($seconds < StorySceneTimingNormalizer::MIN_DURATION_SECONDS) {
            return [];
        }

        return array_map(static fn ($slot): array => [
            'sequence' => $slot->sequence,
            'duration_seconds' => $slot->durationSeconds,
        ], $this->calculator->split($seconds));
    }

    /**
     * @param  list<StoryProductionUnit>  $units
     * @return array<string, mixed>
     */
    private function estimate(Project $project, StoryProductionPlan $plan, array $units): array
    {
        $amount = 0.0;
        $quantity = 0.0;
        $available = $units !== [];
        foreach ($units as $unit) {
            $estimate = $this->costs->estimate($project, 'unit_video', [
                'plan_uuid' => $plan->uuid,
                'unit_uuid' => $unit->uuid,
            ]);
            $quantity += (float) $estimate->quantity;
            if (! $estimate->available || $estimate->amountUsd === null) {
                $available = false;
            } else {
                $amount += (float) $estimate->amountUsd;
            }
        }

        return [
            'kind' => 'unit_video',
            'quantity' => $quantity,
            'amount_usd' => $available ? round($amount, 4) : null,
            'available' => $available,
            'label' => 'not charged now',
        ];
    }

    private function plan(Project $project, string $planUuid): StoryProductionPlan
    {
        try {
            $plan = $this->plans->getForProject($project, $planUuid);
        } catch (StoryException) {
            throw StoryException::notFound('Production plan');
        }
        if (! $plan->isCurrent()) {
            throw StoryProductionPlanException::notCurrent();
        }
        $plan->loadMissing('reel');

        return $plan;
    }

    private function safePath(mixed $disk, mixed $path): bool
    {
        return is_string($disk) && $disk !== '' && is_string($path) && $path !== ''
            && ! str_contains($path, '..') && ! str_contains($path, '://');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    private function remembered(int $workspaceId, array $options): ?array
    {
        $key = $options['idempotency_key'] ?? null;
        if (! is_string($key) || trim($key) === '') {
            return null;
        }
        $cached = Cache::get('story-voice-timing-result:'.$workspaceId.':'.trim($key));

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $result
     */
    private function remember(int $workspaceId, array $options, array $result): void
    {
        $key = $options['idempotency_key'] ?? null;
        if (! is_string($key) || trim($key) === '') {
            return;
        }
        Cache::put('story-voice-timing-result:'.$workspaceId.':'.trim($key), $result, now()->addMinutes(30));
    }
}
