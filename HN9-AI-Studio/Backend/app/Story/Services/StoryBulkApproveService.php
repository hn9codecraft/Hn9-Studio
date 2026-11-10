<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Models\Project;
use App\Models\User;
use App\Story\Enums\StoryCharacterReferenceStatus;
use App\Story\Enums\StoryCharacterStatus;
use App\Story\Enums\StoryProductionPlanStatus;
use App\Story\Enums\StoryReviewStatus;
use App\Story\Enums\StoryStyleReferenceStatus;
use App\Story\Exceptions\StoryBulkApproveException;
use App\Story\Exceptions\StoryException;
use App\Story\Models\StoryCharacterReference;
use App\Story\Models\StoryProductionPlan;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryProductionUnitVersion;
use App\Story\Models\StorySceneAudio;
use App\Story\Models\StoryStyleReference;
use App\Story\Models\StoryWorkspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Approves an explicit selection through the existing approve actions. Nothing paid is started.
 */
final readonly class StoryBulkApproveService
{
    /** @var list<string> */
    public const KINDS = ['unit_version', 'scene_audio', 'character_reference', 'style_reference'];

    public function __construct(
        private StoryProductionUnitVersionService $unitVersions,
        private StoryCharacterReferenceService $characterReferences,
        private StoryStyleReferenceService $styleReferences,
        private StoryAudioService $audio,
        private StoryCostEstimator $estimator,
        private StorySpendService $spend,
        private ActivityLoggerInterface $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $scope
     * @param  list<string>  $ids
     * @return array<string, mixed>
     */
    public function preview(Project $project, string $kind, array $scope, array $ids): array
    {
        $this->assertKind($kind);
        $ids = $this->assertIds($ids);
        $workspace = $this->workspace($project);
        $items = $this->classify($project, $workspace, $kind, $scope, $ids);
        $summary = $this->spend->summary($project);

        return [
            'kind' => $kind,
            'items' => $items,
            'next_paid_step' => $this->nextPaidStep($project, $workspace, $kind, $scope),
            'budget' => [
                'remaining' => $summary['remaining']['amount'] ?? null,
                'currency' => $summary['budget']['currency'] ?? ($summary['display_currency'] ?? 'INR'),
            ],
            'selection_hash' => $this->hash($kind, $scope, $items),
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  list<string>  $ids
     * @return array<string, mixed>
     */
    public function approve(Project $project, string $kind, array $scope, array $ids, string $selectionHash, User $actor): array
    {
        $this->assertKind($kind);
        $ids = $this->assertIds($ids);
        $workspace = $this->workspace($project);
        $items = $this->classify($project, $workspace, $kind, $scope, $ids);
        if (! hash_equals($this->hash($kind, $scope, $items), strtolower($selectionHash))) {
            throw StoryBulkApproveException::changed();
        }

        $lock = Cache::lock('story-bulk-approve:'.$workspace->uuid.':'.$kind, 120);
        if (! $lock->get()) {
            throw StoryBulkApproveException::inProgress();
        }

        $approved = [];
        $skipped = [];
        $failed = [];
        try {
            foreach ($items as $item) {
                if ($item['eligible'] !== true) {
                    $skipped[] = ['id' => $item['id'], 'reason' => $item['reason']];

                    continue;
                }
                try {
                    $this->approveOne($project, $kind, $item, $actor);
                    $approved[] = $item['id'];
                } catch (Throwable $exception) {
                    $failed[] = ['id' => $item['id'], 'reason' => $exception->getMessage()];
                }
            }
        } finally {
            $lock->release();
        }

        try {
            $this->activity->log('story.bulk_approved', $workspace, $actor, 'Bulk approve', [
                'kind' => $kind,
                'scope' => $scope['plan_uuid'] ?? $scope['reel_uuid'] ?? $project->uuid,
                'approved' => count($approved),
                'skipped' => count($skipped),
                'failed' => count($failed),
            ]);
        } catch (Throwable) {
            // The approvals already stand. A history miss must not undo them.
        }

        return [
            'approved' => $approved,
            'skipped' => $skipped,
            'failed' => $failed,
            'counts' => [
                'approved' => count($approved),
                'skipped' => count($skipped),
                'failed' => count($failed),
            ],
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function assertIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_string($id) && $id !== '') {
                $clean[] = $id;
            }
        }
        $max = (int) config('story_bulk.max_items', 50);
        if ($clean === [] || count($clean) > $max) {
            throw StoryBulkApproveException::selection();
        }

        return array_values(array_unique($clean));
    }

    private function assertKind(string $kind): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw StoryBulkApproveException::kind();
        }
    }

    private function workspace(Project $project): StoryWorkspace
    {
        $workspace = StoryWorkspace::query()->where('project_id', $project->id)->first();
        if (! $workspace instanceof StoryWorkspace) {
            throw StoryException::notFound('Story workspace');
        }

        return $workspace;
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function classify(Project $project, StoryWorkspace $workspace, string $kind, array $scope, array $ids): array
    {
        $rows = match ($kind) {
            'unit_version' => $this->unitVersions($workspace, $scope, $ids),
            'scene_audio' => $this->sceneAudio($scope, $ids),
            'character_reference' => $this->characterReferences($project, $ids),
            'style_reference' => $this->styleReferences($workspace, $ids),
            default => [],
        };

        return $this->withPermission($rows, $kind);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withPermission(array $rows, string $kind): array
    {
        foreach ($rows as $index => $row) {
            if ($row['eligible'] !== true || ! isset($row['subject'])) {
                unset($rows[$index]['subject']);

                continue;
            }
            $ability = $kind === 'unit_version' ? 'review' : 'review';
            if (! Gate::allows($ability, $row['subject'])) {
                $rows[$index]['eligible'] = false;
                $rows[$index]['reason'] = 'not_in_scope';
            }
            unset($rows[$index]['subject']);
        }

        return array_values($rows);
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function unitVersions(StoryWorkspace $workspace, array $scope, array $ids): array
    {
        $planUuid = is_string($scope['plan_uuid'] ?? null) ? $scope['plan_uuid'] : '';
        $plan = StoryProductionPlan::query()->where('uuid', $planUuid)->where('story_workspace_id', $workspace->id)->first();
        $versions = StoryProductionUnitVersion::query()->whereIn('uuid', $ids)->with('unit.planScene')->get()->keyBy('uuid');
        $byUnit = [];
        foreach ($ids as $id) {
            $version = $versions->get($id);
            $unitId = $version instanceof StoryProductionUnitVersion ? (int) $version->story_production_unit_id : 0;
            $inPlan = $version instanceof StoryProductionUnitVersion
                && $plan instanceof StoryProductionPlan
                && (int) ($version->unit?->planScene?->story_production_plan_id) === (int) $plan->id;
            if ($inPlan && $unitId > 0) {
                $byUnit[$unitId][] = $id;
            }
        }
        $conflicts = [];
        foreach ($byUnit as $unitIds) {
            if (count($unitIds) > 1) {
                foreach ($unitIds as $id) {
                    $conflicts[$id] = true;
                }
            }
        }

        $rows = [];
        foreach ($ids as $id) {
            $version = $versions->get($id);
            if (! $version instanceof StoryProductionUnitVersion || ! $plan instanceof StoryProductionPlan) {
                $rows[] = $this->item($id, 'Clip', 'missing', false, 'not_in_scope');

                continue;
            }
            $inPlan = (int) ($version->unit?->planScene?->story_production_plan_id) === (int) $plan->id;
            if (! $inPlan) {
                $rows[] = $this->item($id, 'Clip', (string) $version->review_status, false, 'not_in_scope');

                continue;
            }
            $label = 'Unit '.($version->unit?->sequence ?? '').' version '.$version->version_number;
            if ($plan->statusEnum() !== StoryProductionPlanStatus::Active) {
                $rows[] = $this->item($id, $label, (string) $version->review_status, false, 'superseded_plan', $plan);

                continue;
            }
            if (isset($conflicts[$id])) {
                $rows[] = $this->item($id, $label, (string) $version->review_status, false, 'conflict', $plan);

                continue;
            }
            if ($version->reviewStatusEnum() === StoryReviewStatus::Approved) {
                $rows[] = $this->item($id, $label, 'approved', false, 'already_approved', $plan);

                continue;
            }
            if ($version->reviewStatusEnum() === StoryReviewStatus::PendingReview && $this->versionFileReady($version)) {
                $rows[] = $this->item($id, $label, 'pending_review', true, null, $plan, [
                    'plan_uuid' => $plan->uuid,
                    'unit_uuid' => $version->unit?->uuid,
                    'version_uuid' => $version->uuid,
                ]);

                continue;
            }
            $rows[] = $this->item($id, $label, (string) $version->review_status, false, 'wrong_status', $plan);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function sceneAudio(array $scope, array $ids): array
    {
        $reelUuid = is_string($scope['reel_uuid'] ?? null) ? $scope['reel_uuid'] : '';
        $audios = StorySceneAudio::query()->whereIn('uuid', $ids)->with('scene.reel')->get()->keyBy('uuid');
        $rows = [];
        foreach ($ids as $id) {
            $audio = $audios->get($id);
            if (! $audio instanceof StorySceneAudio || $audio->scene?->reel?->uuid !== $reelUuid) {
                $rows[] = $this->item($id, 'Voiceover', 'missing', false, 'not_in_scope');

                continue;
            }
            $label = 'Voiceover '.($audio->scene?->title ?: $audio->uuid);
            $subject = $audio->scene;
            if ($audio->reviewStatusEnum() === StoryReviewStatus::Approved) {
                $rows[] = $this->item($id, $label, 'approved', false, 'already_approved', $subject);

                continue;
            }
            if ($audio->reviewStatusEnum() === StoryReviewStatus::PendingReview && $this->audioReady($audio)) {
                $rows[] = $this->item($id, $label, 'pending_review', true, null, $subject, [
                    'reel_uuid' => $reelUuid,
                    'scene_uuid' => $audio->scene?->uuid,
                    'audio_uuid' => $audio->uuid,
                ]);

                continue;
            }
            $rows[] = $this->item($id, $label, (string) $audio->review_status, false, 'wrong_status', $subject);
        }

        return $rows;
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function characterReferences(Project $project, array $ids): array
    {
        $references = StoryCharacterReference::query()->whereIn('uuid', $ids)->with('character.workspace')->get()->keyBy('uuid');
        $rows = [];
        foreach ($ids as $id) {
            $reference = $references->get($id);
            $owned = $reference instanceof StoryCharacterReference
                && (int) ($reference->character?->workspace?->project_id) === (int) $project->id;
            if (! $owned) {
                $rows[] = $this->item($id, 'Reference picture', 'missing', false, 'not_in_scope');

                continue;
            }
            $label = ($reference->character?->name ?: 'Character').' picture';
            if ($reference->character?->statusEnum() === StoryCharacterStatus::Archived
                || $reference->statusEnum() === StoryCharacterReferenceStatus::Archived) {
                $rows[] = $this->item($id, $label, (string) $reference->status, false, 'archived', $reference);

                continue;
            }
            if ($reference->statusEnum() === StoryCharacterReferenceStatus::Approved) {
                $rows[] = $this->item($id, $label, 'approved', false, 'already_approved', $reference);

                continue;
            }
            if ($reference->statusEnum()->isReviewable()) {
                $rows[] = $this->item($id, $label, (string) $reference->status, true, null, $reference, [
                    'character_uuid' => $reference->character?->uuid,
                    'reference_uuid' => $reference->uuid,
                ]);

                continue;
            }
            $rows[] = $this->item($id, $label, (string) $reference->status, false, 'wrong_status', $reference);
        }

        return $rows;
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function styleReferences(StoryWorkspace $workspace, array $ids): array
    {
        $references = StoryStyleReference::query()->whereIn('uuid', $ids)->with('styleBible')->get()->keyBy('uuid');
        $rows = [];
        foreach ($ids as $id) {
            $reference = $references->get($id);
            $owned = $reference instanceof StoryStyleReference
                && (int) ($reference->styleBible?->story_workspace_id) === (int) $workspace->id;
            if (! $owned) {
                $rows[] = $this->item($id, 'Style picture', 'missing', false, 'not_in_scope');

                continue;
            }
            if ($reference->statusEnum() === StoryStyleReferenceStatus::Archived) {
                $rows[] = $this->item($id, 'Style picture', (string) $reference->status, false, 'archived', $reference);

                continue;
            }
            if ($reference->statusEnum() === StoryStyleReferenceStatus::Approved) {
                $rows[] = $this->item($id, 'Style picture', 'approved', false, 'already_approved', $reference);

                continue;
            }
            if ($reference->statusEnum()->isReviewable()) {
                $rows[] = $this->item($id, 'Style picture', (string) $reference->status, true, null, $reference, [
                    'reference_uuid' => $reference->uuid,
                ]);

                continue;
            }
            $rows[] = $this->item($id, 'Style picture', (string) $reference->status, false, 'wrong_status', $reference);
        }

        return $rows;
    }

    /**
     * @param  array<string, string|null>  $target
     * @return array<string, mixed>
     */
    private function item(string $id, string $label, string $status, bool $eligible, ?string $reason, mixed $subject = null, array $target = []): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'eligible' => $eligible,
            'reason' => $reason,
            'cost_free' => true,
            'subject' => $subject,
            'target' => $target,
        ];
    }

    private function versionFileReady(StoryProductionUnitVersion $version): bool
    {
        $path = (string) $version->path;
        if ($version->disk !== 'videos' || $path === '' || str_contains($path, '..') || str_contains($path, '://')) {
            return false;
        }

        return Storage::disk('videos')->exists($path) && Storage::disk('videos')->size($path) > 0;
    }

    private function audioReady(StorySceneAudio $audio): bool
    {
        return $audio->status === 'completed'
            && is_string($audio->disk)
            && $audio->disk !== ''
            && is_string($audio->path)
            && $audio->path !== ''
            && Storage::disk($audio->disk)->exists($audio->path);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function approveOne(Project $project, string $kind, array $item, User $actor): void
    {
        $target = is_array($item['target'] ?? null) ? $item['target'] : [];
        match ($kind) {
            'unit_version' => $this->unitVersions->approve(
                $project,
                (string) ($target['plan_uuid'] ?? ''),
                (string) ($target['unit_uuid'] ?? ''),
                (string) ($target['version_uuid'] ?? ''),
                $actor,
            ),
            'character_reference' => $this->characterReferences->approve(
                $project,
                (string) ($target['character_uuid'] ?? ''),
                (string) ($target['reference_uuid'] ?? ''),
                $actor,
            ),
            'style_reference' => $this->styleReferences->approve(
                $project,
                (string) ($target['reference_uuid'] ?? ''),
                $actor,
            ),
            'scene_audio' => $this->audio->approve(
                $project,
                (string) ($target['reel_uuid'] ?? ''),
                (string) ($target['scene_uuid'] ?? ''),
                (string) ($target['audio_uuid'] ?? ''),
                $actor,
            ),
            default => throw StoryBulkApproveException::kind(),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function hash(string $kind, array $scope, array $items): string
    {
        $eligible = [];
        foreach ($items as $item) {
            if (($item['eligible'] ?? false) === true) {
                $eligible[] = ['id' => (string) $item['id'], 'status' => (string) $item['status']];
            }
        }
        usort($eligible, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        $payload = [
            'kind' => $kind,
            'scope' => [
                'plan_uuid' => $scope['plan_uuid'] ?? null,
                'reel_uuid' => $scope['reel_uuid'] ?? null,
            ],
            'items' => $eligible,
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>|null
     */
    private function nextPaidStep(Project $project, StoryWorkspace $workspace, string $kind, array $scope): ?array
    {
        if ($kind === 'character_reference') {
            return ['label' => 'Locking a character is free.', 'available' => false, 'count' => 0];
        }
        if ($kind !== 'unit_version') {
            return null;
        }
        $planUuid = is_string($scope['plan_uuid'] ?? null) ? $scope['plan_uuid'] : '';
        $plan = StoryProductionPlan::query()->where('uuid', $planUuid)->where('story_workspace_id', $workspace->id)->first();
        if (! $plan instanceof StoryProductionPlan) {
            return ['label' => 'Units still without a clip: 0', 'available' => false, 'count' => 0];
        }
        $missing = StoryProductionUnit::query()
            ->whereHas('planScene', static function ($query) use ($plan): void {
                $query->where('story_production_plan_id', $plan->id);
            })
            ->whereNull('selected_version_id')
            ->whereDoesntHave('versions', static function ($query): void {
                $query->where('review_status', StoryReviewStatus::Approved->value);
            })
            ->get();
        $amount = 0.0;
        $available = false;
        foreach ($missing as $unit) {
            $estimate = $this->estimator->estimate($project, 'unit_video', [
                'plan_uuid' => $plan->uuid,
                'unit_uuid' => $unit->uuid,
            ]);
            if ($estimate->available && $estimate->amountDisplay !== null) {
                $available = true;
                $amount += (float) $estimate->amountDisplay;
            }
        }
        $count = $missing->count();
        $label = 'Units still without a clip: '.$count;
        if ($available) {
            $label .= ', about ₹'.number_format($amount, 2, '.', '').' if generated';
        }

        return ['label' => $label, 'available' => $available, 'count' => $count];
    }
}
