<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryReelServiceInterface;
use App\Story\Contracts\StorySceneServiceInterface;
use App\Story\Enums\StoryReviewStatus;
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
