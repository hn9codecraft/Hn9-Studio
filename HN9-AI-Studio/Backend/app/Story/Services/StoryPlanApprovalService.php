<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryPlanApprovalServiceInterface;
use App\Story\Contracts\StoryPlanMaterializerInterface;
use App\Story\Contracts\StoryPlanRepositoryInterface;
use App\Story\Contracts\StoryPlanVersionRepositoryInterface;
use App\Story\Contracts\StoryProductionPlanServiceInterface;
use App\Story\Contracts\StoryWorkspaceServiceInterface;
use App\Story\Enums\StoryPlanVersionStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Exceptions\StoryPlanApprovalException;
use App\Story\Exceptions\StoryRuntimeException;
use App\Story\Models\StoryPlan;
use App\Story\Models\StoryPlanVersion;
use App\Story\Models\StoryProductionPlan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Story approval → production plan. The whole step runs in one transaction under the story
 * plan row lock that the materializer and the production plan service also take, so the
 * version is never left approved without its plan, and concurrent approvals queue up.
 */
final readonly class StoryPlanApprovalService implements StoryPlanApprovalServiceInterface
{
    public const EVENT_APPROVED = 'story.plan_version.approved';

    public const EVENT_APPROVAL_FAILED = 'story.plan_version.approval_failed';

    public const EVENT_PLAN_REUSED = 'story.production_plan.reused';

    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private StoryWorkspaceServiceInterface $workspaces,
        private StoryPlanRepositoryInterface $plans,
        private StoryPlanVersionRepositoryInterface $versions,
        private StoryPlanMaterializerInterface $materializer,
        private StoryProductionPlanServiceInterface $productionPlans,
        private ActivityLoggerInterface $activity,
    ) {}

    public function approve(Project $project, string $storyPlanUuid, string $versionUuid, User $actor): array
    {
        $workspace = $this->workspaces->workspaceForProject($project);
        $storyPlan = $this->plans->findByUuidForWorkspace($workspace, $storyPlanUuid)
            ?? throw StoryException::notFound('Story plan');
        $version = $this->versions->findByUuidForPlan($storyPlan, $versionUuid)
            ?? throw StoryException::notFound('Story plan version');

        try {
            [$productionPlan, $approved, $created] = DB::transaction(
                fn (): array => $this->approveLocked($project, $storyPlan, $version, $actor),
                self::DEADLOCK_ATTEMPTS,
            );
        } catch (StoryException $exception) {
            $failure = $this->publicFailure($exception, $version);
            $this->recordFailure($version, $actor, $failure);
            throw $failure;
        } catch (Throwable $exception) {
            $this->logFailure($version, $exception);
            $failure = StoryPlanApprovalException::failed();
            $this->recordFailure($version, $actor, $failure);
            throw $failure;
        }

        return [
            'plan' => $this->plans->findByUuidForWorkspace($workspace, $storyPlanUuid) ?? $storyPlan,
            'version' => $version->refresh(),
            'production_plan' => $productionPlan,
            'approved' => $approved,
            'created' => $created,
        ];
    }

    /**
     * @return array{0: StoryProductionPlan, 1: bool, 2: bool}
     */
    private function approveLocked(Project $project, StoryPlan $storyPlan, StoryPlanVersion $version, User $actor): array
    {
        $latestVersionId = StoryPlan::query()->whereKey($storyPlan->id)->lockForUpdate()->value('current_version_id');
        $version = StoryPlanVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();

        match ($version->statusEnum()) {
            StoryPlanVersionStatus::Completed => null,
            StoryPlanVersionStatus::Failed => throw StoryPlanApprovalException::failedVersion(),
            default => throw StoryPlanApprovalException::unfinished(),
        };

        if ($version->isApproved()) {
            $existing = StoryProductionPlan::query()
                ->where('story_plan_version_id', $version->id)
                ->orderByDesc('revision')
                ->first();
            if ($existing !== null) {
                $this->activity->log(self::EVENT_PLAN_REUSED, $existing, $actor, 'Production plan already prepared', [
                    'revision' => $existing->revision,
                    'version' => $version->version,
                ]);

                return [$this->productionPlans->getForProject($project, $existing->uuid), false, false];
            }
        } elseif ((int) $version->id !== (int) $latestVersionId) {
            throw StoryPlanApprovalException::notLatest();
        }

        $this->createScenes($project, $storyPlan, $version);

        $newlyApproved = ! $version->isApproved();
        if ($newlyApproved) {
            $version->forceFill(['approved_at' => now(), 'approved_by' => $actor->id])->save();
            $this->activity->log(self::EVENT_APPROVED, $version, $actor, 'Story approved', [
                'version' => $version->version,
            ]);
        }

        $current = StoryProductionPlan::query()
            ->where('current_for_story_plan_id', $storyPlan->id)
            ->first(['id', 'uuid', 'story_plan_version_id']);
        $result = $current === null || (int) $current->story_plan_version_id === (int) $version->id
            ? $this->productionPlans->createForVersion($project, $storyPlan->uuid, $version->uuid, $actor)
            : $this->productionPlans->revise($project, $current->uuid, $version->uuid, $actor);

        return [$result['plan'], $newlyApproved, $result['created']];
    }

    /**
     * Creates the version's scenes, or reuses them when they already exist.
     */
    private function createScenes(Project $project, StoryPlan $storyPlan, StoryPlanVersion $version): void
    {
        try {
            $this->materializer->materialize($project, $storyPlan->uuid, $version->uuid);
        } catch (StoryRuntimeException $exception) {
            if (! in_array($exception->errorCode(), ['story_materialization_failed', 'story_scene_invalid_duration'], true)) {
                throw $exception;
            }
            if ($exception->getPrevious() !== null) {
                throw $exception->getPrevious();
            }
            Log::warning('Story approval stopped: the plan has scenes that cannot be created.', [
                'story_plan_version' => $version->uuid,
                'detail' => Str::limit($exception->getMessage(), 300),
            ]);

            throw StoryPlanApprovalException::invalidPlan();
        }
    }

    private function publicFailure(StoryException $exception, StoryPlanVersion $version): StoryException
    {
        if ($exception->statusCode() < 500) {
            return $exception;
        }
        // The production plan service has already logged the cause of its own failures.
        if ($exception->errorCode() !== 'story_production_plan_failed') {
            $this->logFailure($version, $exception);
        }

        return StoryPlanApprovalException::failed();
    }

    private function logFailure(StoryPlanVersion $version, Throwable $exception): void
    {
        $root = $exception instanceof StoryException && $exception->getPrevious() !== null
            ? $exception->getPrevious()
            : $exception;
        // A query exception's message embeds the SQL and every bound value; keep only the driver error.
        $detail = $root instanceof QueryException
            ? ($root->getPrevious()?->getMessage() ?? 'Query failed.')
            : $root->getMessage();

        Log::error('Story approval failed and was rolled back.', [
            'story_plan_version' => $version->uuid,
            'exception' => $root::class,
            'message' => Str::limit($detail, 300),
        ]);
    }

    /**
     * Written after the rollback so the failure stays in History.
     */
    private function recordFailure(StoryPlanVersion $version, User $actor, StoryException $failure): void
    {
        try {
            $this->activity->log(self::EVENT_APPROVAL_FAILED, $version, $actor, 'Story approval failed', [
                'version' => $version->version,
                'error_code' => $failure->errorCode(),
                'reason' => $failure->getMessage(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Story approval failure could not be recorded in history.', [
                'story_plan_version' => $version->uuid,
                'exception' => $exception::class,
            ]);
        }
    }
}
