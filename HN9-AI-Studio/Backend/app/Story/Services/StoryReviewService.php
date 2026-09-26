<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryReelServiceInterface;
use App\Story\Contracts\StorySceneServiceInterface;
use App\Story\Enums\StoryReviewStatus;
use App\Story\Enums\StoryVideoInputType;
use App\Story\Exceptions\StoryException;
use App\Story\Exceptions\StoryReviewException;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryReelComment;
use App\Story\Models\StoryReelVersion;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneComment;
use App\Story\Models\StorySceneVersion;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Video\StoryVideoGenerationRequest;
use App\Story\Video\StoryVideoInput;
use Illuminate\Support\Facades\Storage;

/**
 * Submit-then-approve review for story scenes and reels.
 * Approval is enforced by policy: project owner or admin.
 */
final readonly class StoryReviewService
{
    public function __construct(
        private StorySceneServiceInterface $scenes,
        private StoryReelServiceInterface $reels,
        private StoryContinuityService $continuity,
        private StoryVideoDispatchService $dispatch,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function sceneVersions(Project $project, string $reelUuid, string $sceneUuid): array
    {
        $scene = $this->scene($project, $reelUuid, $sceneUuid);

        return StorySceneVersion::query()
            ->where('story_scene_id', $scene->id)
            ->with('comments.author')
            ->orderBy('version')
            ->get()
            ->map(fn (StorySceneVersion $version): array => $this->sceneVersionPayload($version))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function commentOnScene(
        Project $project,
        string $reelUuid,
        string $sceneUuid,
        User $actor,
        string $body,
    ): array {
        $scene = $this->scene($project, $reelUuid, $sceneUuid);
        $version = $this->latestSceneVersion($scene) ?? $this->openSceneVersion($scene, null, null);

        $comment = StorySceneComment::query()->create([
            'story_scene_version_id' => $version->id,
            'user_id' => $actor->id,
            'body' => $body,
        ]);

        return [
            'id' => $comment->uuid,
            'body' => $comment->body,
            'version_id' => $version->uuid,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function submitScene(Project $project, string $reelUuid, string $sceneUuid, ?string $comment): array
    {
        $scene = $this->scene($project, $reelUuid, $sceneUuid);
        $version = $this->latestSceneVersion($scene) ?? $this->openSceneVersion($scene, null, $comment);

        if (! $version->statusEnum()->isSubmittable()) {
            throw StoryReviewException::invalidTransition($version->status, 'submitted for review');
        }

        $version->forceFill([
            'status' => StoryReviewStatus::PendingReview->value,
            'submitted_at' => now(),
            'review_comment' => $comment ?? $version->review_comment,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ])->save();

        return $this->sceneVersionPayload($version->fresh('comments.author'));
    }

    /**
     * @return array<string, mixed>
     */
    public function approveScene(Project $project, string $reelUuid, string $sceneUuid, User $actor, ?string $comment): array
    {
        return $this->decideScene($project, $reelUuid, $sceneUuid, $actor, $comment, StoryReviewStatus::Approved);
    }

    /**
     * @return array<string, mixed>
     */
    public function reworkScene(Project $project, string $reelUuid, string $sceneUuid, User $actor, string $comment): array
    {
        return $this->decideScene($project, $reelUuid, $sceneUuid, $actor, $comment, StoryReviewStatus::NeedsRework);
    }

    /**
     * @return array<string, mixed>
     */
    public function regenerateScene(
        Project $project,
        string $reelUuid,
        string $sceneUuid,
        ?string $comment,
    ): array {
        $scene = $this->scene($project, $reelUuid, $sceneUuid);
        $parent = $this->latestSceneVersion($scene);
        $package = $this->continuity->packageForScene($project, $reelUuid, $sceneUuid);
        if ($comment !== null && $comment !== '') {
            $package['review_comment'] = $comment;
        }

        $version = $this->openSceneVersion($scene, $parent, $comment, $package);

        $this->dispatch->start($project, new StoryVideoGenerationRequest(
            capability: StoryVideoCapability::TextToVideo,
            reelUuid: $reelUuid,
            sceneUuid: $sceneUuid,
            prompt: (string) ($scene->visual_prompt ?: $scene->story ?: $scene->title),
            durationSeconds: (int) $scene->duration_seconds,
            idempotencyKey: 'scene-version-'.$version->uuid,
        ));

        return $this->sceneVersionPayload($version->fresh(['comments.author', 'parentVersion']));
    }

    /**
     * @return array<string, mixed>
     */
    public function editScene(
        Project $project,
        string $reelUuid,
        string $sceneUuid,
        string $versionUuid,
        string $instruction,
    ): array {
        return $this->reviseScene($project, $reelUuid, $sceneUuid, $versionUuid, $instruction, StoryVideoCapability::VideoEdit);
    }

    /**
     * @return array<string, mixed>
     */
    public function extendScene(
        Project $project,
        string $reelUuid,
        string $sceneUuid,
        string $versionUuid,
        string $instruction,
    ): array {
        return $this->reviseScene($project, $reelUuid, $sceneUuid, $versionUuid, $instruction, StoryVideoCapability::VideoExtend);
    }

    /**
     * @return array<string, mixed>
     */
    public function versionStatus(Project $project, string $reelUuid, string $sceneUuid, string $versionUuid): array
    {
        $scene = $this->scene($project, $reelUuid, $sceneUuid);
        $version = $this->sceneVersion($scene, $versionUuid);
        $job = $this->versionJob($scene, $version);

        return [
            'version_id' => $version->uuid,
            'output_url' => null,
            'has_file' => $this->jobHasFile($job),
            'job' => $job instanceof StoryVideoGenerationJob ? $this->jobSummary($job) : null,
        ];
    }

    public function versionFile(Project $project, string $reelUuid, string $sceneUuid, string $versionUuid): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $scene = $this->scene($project, $reelUuid, $sceneUuid);
        $version = $this->sceneVersion($scene, $versionUuid);
        $job = $this->versionJob($scene, $version);
        if (! $job instanceof StoryVideoGenerationJob || ! $this->jobHasFile($job)) {
            throw StoryVideoEngineException::invalidInput('No private video file is stored for this scene version.');
        }

        return $this->dispatch->file($project, $job);
    }

    /**
     * @return array<string, mixed>
     */
    public function scenePreview(Project $project, string $reelUuid, string $sceneUuid): array
    {
        $scene = $this->scene($project, $reelUuid, $sceneUuid);
        $version = $this->latestSceneVersion($scene);
        $job = $this->sceneJob($scene);

        return [
            'scene_id' => $scene->uuid,
            'version_id' => $version?->uuid,
            'status' => $version?->status,
            'output_url' => null,
            'has_file' => $this->jobHasFile($job),
        ];
    }

    public function sceneFile(Project $project, string $reelUuid, string $sceneUuid): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $scene = $this->scene($project, $reelUuid, $sceneUuid);
        $job = $this->sceneJob($scene);
        if (! $job instanceof StoryVideoGenerationJob || ! $this->jobHasFile($job)) {
            throw StoryVideoEngineException::invalidInput('No private video file is stored for this scene.');
        }

        return $this->dispatch->file($project, $job);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function reelVersions(Project $project, string $reelUuid): array
    {
        $reel = $this->reel($project, $reelUuid);

        return StoryReelVersion::query()
            ->where('story_reel_id', $reel->id)
            ->with('comments.author')
            ->orderBy('version')
            ->get()
            ->map(fn (StoryReelVersion $version): array => $this->reelVersionPayload($version))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function commentOnReel(Project $project, string $reelUuid, User $actor, string $body): array
    {
        $reel = $this->reel($project, $reelUuid);
        $version = $this->latestReelVersion($reel) ?? $this->openReelVersion($reel, null);

        $comment = StoryReelComment::query()->create([
            'story_reel_version_id' => $version->id,
            'user_id' => $actor->id,
            'body' => $body,
        ]);

        return [
            'id' => $comment->uuid,
            'body' => $comment->body,
            'version_id' => $version->uuid,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function submitReel(Project $project, string $reelUuid, ?string $comment): array
    {
        $reel = $this->reel($project, $reelUuid);
        $version = $this->latestReelVersion($reel) ?? $this->openReelVersion($reel, $comment);
        if (! $version->statusEnum()->isSubmittable()) {
            throw StoryReviewException::invalidTransition($version->status, 'submitted for review');
        }

        $version->forceFill([
            'status' => StoryReviewStatus::PendingReview->value,
            'submitted_at' => now(),
            'review_comment' => $comment ?? $version->review_comment,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ])->save();

        return $this->reelVersionPayload($version->fresh('comments.author'));
    }

    /**
     * @return array<string, mixed>
     */
    public function approveReel(Project $project, string $reelUuid, User $actor, ?string $comment): array
    {
        return $this->decideReel($project, $reelUuid, $actor, $comment, StoryReviewStatus::Approved);
    }

    /**
     * @return array<string, mixed>
     */
    public function reworkReel(Project $project, string $reelUuid, User $actor, string $comment): array
    {
        return $this->decideReel($project, $reelUuid, $actor, $comment, StoryReviewStatus::NeedsRework);
    }

    /**
     * @return array<string, mixed>
     */
    private function reviseScene(
        Project $project,
        string $reelUuid,
        string $sceneUuid,
        string $versionUuid,
        string $instruction,
        StoryVideoCapability $capability,
    ): array {
        $scene = $this->scene($project, $reelUuid, $sceneUuid);
        $source = $this->sceneVersion($scene, $versionUuid);
        $instruction = trim($instruction);
        if ($instruction === '') {
            throw StoryVideoEngineException::invalidInput('An edit instruction is required.');
        }

        $sourceJob = $this->versionJob($scene, $source);
        $storage = is_array($sourceJob?->provider_metadata['storage'] ?? null)
            ? $sourceJob->provider_metadata['storage']
            : null;
        if (! $sourceJob instanceof StoryVideoGenerationJob
            || ! $this->jobHasFile($sourceJob)
            || ! is_array($storage)
            || ! Storage::disk((string) $storage['disk'])->exists((string) $storage['path'])) {
            throw StoryVideoEngineException::invalidInput('A stored scene video is required.');
        }

        if (! $this->dispatch->liveSupports($capability)) {
            throw StoryVideoEngineException::generationNotEnabled();
        }

        $scene->loadMissing('reel');
        $key = $capability->value.':'.$source->uuid.':'.hash('sha256', $instruction);
        $existing = StoryVideoGenerationJob::query()
            ->where('story_workspace_id', $scene->reel->story_workspace_id)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing instanceof StoryVideoGenerationJob) {
            $existingVersionId = $existing->provider_metadata['version_id'] ?? null;
            $existingVersion = is_string($existingVersionId)
                ? StorySceneVersion::query()->where('uuid', $existingVersionId)->where('story_scene_id', $scene->id)->first()
                : null;
            if ($existingVersion instanceof StorySceneVersion) {
                return $this->revisionPayload($existingVersion, $existing, false);
            }
        }

        $continuity = is_array($source->continuity) ? $source->continuity : [];
        $continuity['review_comment'] = $instruction;
        $version = $this->openSceneVersion($scene, $source, $instruction, $continuity);
        $started = $this->dispatch->start($project, new StoryVideoGenerationRequest(
            capability: $capability,
            reelUuid: $reelUuid,
            sceneUuid: $sceneUuid,
            prompt: $instruction,
            durationSeconds: 8,
            inputs: [
                new StoryVideoInput(
                    type: StoryVideoInputType::Video,
                    assetId: $source->uuid,
                    metadata: [
                        'disk' => (string) $storage['disk'],
                        'path' => (string) $storage['path'],
                        'mime' => (string) ($storage['mime'] ?? 'video/mp4'),
                    ],
                ),
            ],
            idempotencyKey: $key,
            metadata: [
                'version_id' => $version->uuid,
                'source_version_id' => $source->uuid,
            ],
        ));

        $job = $started['job'];
        $metadata = is_array($job->provider_metadata) ? $job->provider_metadata : [];
        $metadata['version_id'] = $version->uuid;
        $metadata['source_version_id'] = $source->uuid;
        $job->forceFill(['provider_metadata' => $metadata])->save();

        return $this->revisionPayload($version->fresh(['comments.author', 'parentVersion']), $job->fresh() ?? $job, true);
    }

    private function sceneVersion(StoryScene $scene, string $versionUuid): StorySceneVersion
    {
        $version = StorySceneVersion::query()
            ->where('story_scene_id', $scene->id)
            ->where('uuid', $versionUuid)
            ->first();

        if (! $version instanceof StorySceneVersion) {
            throw StoryException::notFound('Scene version');
        }

        return $version;
    }

    private function versionJob(StoryScene $scene, StorySceneVersion $version): ?StoryVideoGenerationJob
    {
        return StoryVideoGenerationJob::query()
            ->where('story_scene_id', $scene->id)
            ->orderByDesc('id')
            ->get()
            ->first(static function (StoryVideoGenerationJob $job) use ($version): bool {
                return ($job->provider_metadata['version_id'] ?? null) === $version->uuid;
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function jobSummary(StoryVideoGenerationJob $job): array
    {
        return [
            'id' => $job->uuid,
            'capability' => $job->capability,
            'status' => $job->status,
            'operation_id' => $job->operation_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionPayload(StorySceneVersion $version, StoryVideoGenerationJob $job, bool $created): array
    {
        return [
            ...$this->sceneVersionPayload($version),
            'created' => $created,
            'job' => $this->jobSummary($job),
            'output_url' => null,
        ];
    }

    private function scene(Project $project, string $reelUuid, string $sceneUuid): StoryScene
    {
        return $this->scenes->getForProjectReel($project, $reelUuid, $sceneUuid);
    }

    private function reel(Project $project, string $reelUuid): StoryReel
    {
        return $this->reels->getForProject($project, $reelUuid);
    }

    private function latestSceneVersion(StoryScene $scene): ?StorySceneVersion
    {
        return StorySceneVersion::query()
            ->where('story_scene_id', $scene->id)
            ->orderByDesc('version')
            ->first();
    }

    private function latestReelVersion(StoryReel $reel): ?StoryReelVersion
    {
        return StoryReelVersion::query()
            ->where('story_reel_id', $reel->id)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @param  array<string, mixed>|null  $continuity
     */
    private function openSceneVersion(
        StoryScene $scene,
        ?StorySceneVersion $parent,
        ?string $comment,
        ?array $continuity = null,
    ): StorySceneVersion {
        $next = (int) StorySceneVersion::query()->where('story_scene_id', $scene->id)->max('version') + 1;

        return StorySceneVersion::query()->create([
            'story_scene_id' => $scene->id,
            'parent_version_id' => $parent?->id,
            'version' => $next,
            'status' => StoryReviewStatus::Draft->value,
            'title' => $scene->title,
            'story' => $scene->story,
            'visual_prompt' => $scene->visual_prompt,
            'continuity' => $continuity ?? $scene->continuity,
            'review_comment' => $comment,
        ]);
    }

    private function openReelVersion(StoryReel $reel, ?string $comment): StoryReelVersion
    {
        $latest = $this->latestReelVersion($reel);
        $next = (int) StoryReelVersion::query()->where('story_reel_id', $reel->id)->max('version') + 1;

        return StoryReelVersion::query()->create([
            'story_reel_id' => $reel->id,
            'parent_version_id' => $latest?->id,
            'version' => $next,
            'status' => StoryReviewStatus::Draft->value,
            'title' => $reel->title,
            'description' => $reel->description,
            'review_comment' => $comment,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decideScene(
        Project $project,
        string $reelUuid,
        string $sceneUuid,
        User $actor,
        ?string $comment,
        StoryReviewStatus $status,
    ): array {
        $version = $this->latestSceneVersion($this->scene($project, $reelUuid, $sceneUuid));
        if (! $version instanceof StorySceneVersion || ! $version->statusEnum()->isReviewable()) {
            throw StoryReviewException::invalidTransition($version?->status ?? 'missing', $status->value);
        }

        $version->forceFill([
            'status' => $status->value,
            'reviewed_at' => now(),
            'reviewed_by' => $actor->id,
            'review_comment' => $comment ?? $version->review_comment,
        ])->save();

        return $this->sceneVersionPayload($version->fresh('comments.author'));
    }

    /**
     * @return array<string, mixed>
     */
    private function decideReel(
        Project $project,
        string $reelUuid,
        User $actor,
        ?string $comment,
        StoryReviewStatus $status,
    ): array {
        $version = $this->latestReelVersion($this->reel($project, $reelUuid));
        if (! $version instanceof StoryReelVersion || ! $version->statusEnum()->isReviewable()) {
            throw StoryReviewException::invalidTransition($version?->status ?? 'missing', $status->value);
        }

        $version->forceFill([
            'status' => $status->value,
            'reviewed_at' => now(),
            'reviewed_by' => $actor->id,
            'review_comment' => $comment ?? $version->review_comment,
        ])->save();

        return $this->reelVersionPayload($version->fresh('comments.author'));
    }

    /**
     * @return array<string, mixed>
     */
    private function sceneVersionPayload(StorySceneVersion $version): array
    {
        $version->loadMissing('comments.author', 'parentVersion');

        return [
            'id' => $version->uuid,
            'version' => $version->version,
            'status' => $version->status,
            'parent_version_id' => $version->parentVersion?->uuid,
            'title' => $version->title,
            'continuity' => $version->continuity,
            'review_comment' => $version->review_comment,
            'output_url' => null,
            'submitted_at' => $version->submitted_at?->toIso8601String(),
            'reviewed_at' => $version->reviewed_at?->toIso8601String(),
            'comments' => $version->comments->map(static fn (StorySceneComment $comment): array => [
                'id' => $comment->uuid,
                'body' => $comment->body,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reelVersionPayload(StoryReelVersion $version): array
    {
        $version->loadMissing('comments.author');

        return [
            'id' => $version->uuid,
            'version' => $version->version,
            'status' => $version->status,
            'title' => $version->title,
            'review_comment' => $version->review_comment,
            'comments' => $version->comments->map(static fn (StoryReelComment $comment): array => [
                'id' => $comment->uuid,
                'body' => $comment->body,
            ])->all(),
        ];
    }

    private function sceneJob(StoryScene $scene): ?StoryVideoGenerationJob
    {
        return StoryVideoGenerationJob::query()
            ->where('story_scene_id', $scene->id)
            ->orderByDesc('id')
            ->first();
    }

    private function jobHasFile(?StoryVideoGenerationJob $job): bool
    {
        if (! $job instanceof StoryVideoGenerationJob) {
            return false;
        }

        $storage = $job->provider_metadata['storage'] ?? null;

        return is_array($storage) && isset($storage['disk'], $storage['path']);
    }
}
