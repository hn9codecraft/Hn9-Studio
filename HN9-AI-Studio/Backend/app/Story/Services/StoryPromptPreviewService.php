<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Contracts\StorySceneServiceInterface;
use App\Story\Exceptions\StoryPromptPreviewException;
use App\Story\Models\StoryProductionUnit;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryUnitPromptEdit;
use App\Story\Models\StoryWorkspace;
use App\Story\Support\StoryCharacterIdentityCard;
use App\Story\Support\StoryComposedPrompt;
use App\Story\Support\StoryCostEstimate;
use App\Story\Support\StoryVideoPromptComposer;
use App\Story\Video\StoryVideoInput;
use App\Story\Video\StoryVideoRoutingDecision;

/**
 * Shows the exact paid request. It does not create a job, spend, or call a provider.
 */
final readonly class StoryPromptPreviewService
{
    public function __construct(
        private StoryProductionPlanServiceInterface $plans,
        private StoryProductionUnitGenerationService $generation,
        private StoryReviewService $reviews,
        private StorySceneServiceInterface $scenes,
        private StorySpendService $spend,
        private ActivityLoggerInterface $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function previewUnit(Project $project, string $planUuid, string $unitUuid, array $input): array
    {
        $draft = $this->generation->draft($project, $planUuid, $unitUuid, $input);
        $estimate = app(StoryCostEstimator::class)->estimate($project, 'unit_video', [
            'plan_uuid' => $planUuid,
            'unit_uuid' => $unitUuid,
        ]);

        return $this->payload($project, $draft['composed'], $draft['decision'], $estimate, $draft['preview_hash'], (bool) $draft['idempotent'], $draft['request']->durationSeconds, $draft['request']->aspectRatio);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function previewSceneRegenerate(Project $project, string $reelUuid, string $sceneUuid, array $input): array
    {
        $scene = $this->scenes->getForProjectReel($project, $reelUuid, $sceneUuid);
        $draft = $this->reviews->regenerateDraft(
            $project,
            $scene,
            $reelUuid,
            (string) ($input['mode'] ?? 'text'),
            isset($input['prompt']) ? (string) $input['prompt'] : null,
            isset($input['reference_uuid']) ? (string) $input['reference_uuid'] : (isset($input['reference_id']) ? (string) $input['reference_id'] : null),
        );

        return $this->payload($project, $draft['composed'], $draft['decision'], $draft['estimate'], $draft['preview_hash'], false, $draft['request']->durationSeconds, $draft['request']->aspectRatio);
    }

    public function saveUnitPrompt(Project $project, string $planUuid, string $unitUuid, string $prompt, User $actor): StoryUnitPromptEdit
    {
        $unit = $this->unit($project, $planUuid, $unitUuid);
        $clean = StoryCharacterIdentityCard::clean($prompt);
        if ($clean === '') {
            throw StoryPromptPreviewException::emptyPrompt();
        }
        if (mb_strlen($clean) > 2000) {
            throw StoryPromptPreviewException::promptTooLong();
        }
        $unit->loadMissing('planScene.scene');
        $scene = $unit->planScene?->scene;
        $automatic = $scene instanceof StoryScene
            ? app(StoryVideoPromptComposer::class)->automaticBase($scene, '')
            : '';
        $edit = StoryUnitPromptEdit::query()->updateOrCreate(
            ['story_production_unit_id' => $unit->id],
            [
                'prompt' => $clean,
                'base_hash' => hash('sha256', $automatic),
                'edited_by' => $actor->id,
            ],
        );
        $this->activity->log('story.unit_prompt.edited', $unit, $actor, 'Unit prompt edited', [
            'unit_uuid' => $unit->uuid,
        ]);

        return $edit;
    }

    public function resetUnitPrompt(Project $project, string $planUuid, string $unitUuid, User $actor): void
    {
        $unit = $this->unit($project, $planUuid, $unitUuid);
        StoryUnitPromptEdit::query()->where('story_production_unit_id', $unit->id)->delete();
        $this->activity->log('story.unit_prompt.reset', $unit, $actor, 'Unit prompt reset', [
            'unit_uuid' => $unit->uuid,
        ]);
    }

    private function unit(Project $project, string $planUuid, string $unitUuid): StoryProductionUnit
    {
        $plan = $this->plans->getForProject($project, $planUuid);
        $unit = StoryProductionUnit::query()
            ->where('uuid', $unitUuid)
            ->whereHas('planScene', static function ($query) use ($plan): void {
                $query->where('story_production_plan_id', $plan->id);
            })
            ->first();
        if (! $unit instanceof StoryProductionUnit) {
            throw \App\Story\Exceptions\StoryException::notFound('Generation unit');
        }

        return $unit;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Project $project,
        StoryComposedPrompt $composed,
        StoryVideoRoutingDecision $decision,
        StoryCostEstimate $estimate,
        string $hash,
        bool $idempotent,
        ?int $duration,
        ?string $aspect,
    ): array {
        $summary = $this->spend->summary($project);
        $budgetUsd = $summary['budget']['amount_usd'] ?? null;
        $wouldExceed = false;
        if ($budgetUsd !== null && $estimate->available && $estimate->amountUsdHigh !== null) {
            $wouldExceed = $this->spend->committedUsd($project) + $estimate->amountUsdHigh > (float) $budgetUsd + 0.0000001;
        }
        $adapter = null;
        if (is_string($decision->providerKey) && $decision->providerKey !== '') {
            foreach (app(\App\Story\Contracts\StoryCapabilityRouterInterface::class)->adapters() as $candidate) {
                if (method_exists($candidate, 'key') && $candidate->key() === $decision->providerKey) {
                    $adapter = $candidate;
                    break;
                }
            }
        }
        $label = $adapter !== null && method_exists($adapter, 'displayName') ? (string) $adapter->displayName() : (string) ($decision->providerKey ?? '');

        return [
            'provider' => [
                'key' => $decision->providerKey,
                'label' => $label,
            ],
            'model' => $decision->modelKey,
            'capability' => $decision->capability->value,
            'duration_seconds' => $duration,
            'aspect_ratio' => $aspect,
            'prompt' => [
                'base' => $composed->base,
                'lock_block' => $composed->lockBlock,
                'final' => $composed->final,
                'length' => mb_strlen($composed->final),
                'limit' => $composed->limit,
                'trimmed' => $composed->trimmed,
                'edited' => $composed->edited,
                'edit_stale' => $composed->editStale,
                'automatic_base' => $composed->editStale ? $composed->automaticBase : null,
            ],
            'locks' => $composed->locks,
            'inputs' => array_map(static fn (StoryVideoInput $input): array => [
                'type' => $input->type->value,
                'asset_id' => $input->assetId,
            ], $composed->inputs),
            'warnings' => $composed->warnings,
            'estimate' => $estimate->toArray(),
            'budget' => [
                'limit' => $summary['budget']['amount'] ?? null,
                'spent' => $summary['spent_total_usd'] ?? 0,
                'remaining' => $summary['remaining']['amount'] ?? null,
                'currency' => $summary['budget']['currency'] ?? ($summary['display_currency'] ?? 'INR'),
                'would_exceed' => $wouldExceed,
            ],
            'routing' => [
                'matched' => $decision->matched && $decision->providerKey !== null,
                'reason' => $decision->errorMessage,
            ],
            'preview_hash' => $hash,
            'idempotent_repeat' => $idempotent,
        ];
    }
}
