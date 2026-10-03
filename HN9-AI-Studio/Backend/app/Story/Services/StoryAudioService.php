<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Models\Project;
use App\Story\Contracts\StorySceneServiceInterface;
use App\Story\Enums\StoryAudioRole;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneAudio;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Video\StoryVideoGenerationRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Scene audio studio. Roles are request data; vendors stay inside adapters.
 */
final readonly class StoryAudioService
{
    public function __construct(
        private StorySceneServiceInterface $scenes,
        private StoryVideoDispatchService $dispatch,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Project $project, string $reelUuid, string $sceneUuid, ?StoryAudioRole $role = null): array
    {
        $scene = $this->scenes->getForProjectReel($project, $reelUuid, $sceneUuid);
        $query = StorySceneAudio::query()
            ->where('story_scene_id', $scene->id)
            ->orderByDesc('id');

        if ($role instanceof StoryAudioRole) {
            $query->where('role', $role->value);
        }

        return $query->get()
            ->map(fn (StorySceneAudio $audio): array => $this->payload($audio))
            ->all();
    }

    /**
     * Every scene's audio in one reel, newest first, without refreshing provider state.
     *
     * @return list<array<string, mixed>>
     */
    public function listForReel(Project $project, StoryReel $reel): array
    {
        return StorySceneAudio::query()
            ->whereHas('scene', static function ($query) use ($reel): void {
                $query->where('story_reel_id', $reel->id);
            })
            ->where('story_workspace_id', $reel->story_workspace_id)
            ->with(['scene', 'version', 'job'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (StorySceneAudio $audio): array => $this->payload($audio))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function create(
        Project $project,
        string $reelUuid,
        string $sceneUuid,
        StoryAudioRole $role,
        string $prompt,
        ?string $versionUuid = null,
    ): array {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw StoryVideoEngineException::invalidInput('An audio prompt is required.');
        }

        $this->dispatch->assertLive($project, StoryVideoCapability::Audio, $reelUuid, $sceneUuid);

        $liveRoles = $this->dispatch->liveAudioRoles();
        if ($liveRoles !== [] && ! in_array($role->value, $liveRoles, true)) {
            throw StoryVideoEngineException::invalidInput(
                'The requested audio role is not supported. No alternate role was substituted.',
            );
        }

        $scene = $this->scenes->getForProjectReel($project, $reelUuid, $sceneUuid);
        $scene->loadMissing('reel.workspace');
        $version = $versionUuid !== null
            ? $this->sceneVersion($scene, $versionUuid)
            : $this->latestSceneVersion($scene);

        $key = 'audio:'.$scene->uuid.':'.$role->value.':'.hash('sha256', $prompt);
        $existing = StorySceneAudio::query()
            ->where('story_workspace_id', $scene->reel->story_workspace_id)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing instanceof StorySceneAudio) {
            return $this->payload($this->syncFromJob($project, $existing), false);
        }

        try {
            $started = $this->dispatch->start($project, new StoryVideoGenerationRequest(
                capability: StoryVideoCapability::Audio,
                reelUuid: $reelUuid,
                sceneUuid: $sceneUuid,
                prompt: $prompt,
                durationSeconds: 8,
                preferredProvider: StoryVideoDispatchService::LIVE_PROVIDER_KEY,
                idempotencyKey: $key,
                metadata: [
                    'audio_role' => $role->value,
                    'version_id' => $version?->uuid,
                ],
            ));
        } catch (StoryVideoEngineException $exception) {
            throw $exception;
        }

        $job = $started['job'];
        $audio = StorySceneAudio::query()->create([
            'story_workspace_id' => $scene->reel->story_workspace_id,
            'story_scene_id' => $scene->id,
            'story_scene_version_id' => $version?->id,
            'story_video_generation_job_id' => $job->id,
            'role' => $role->value,
            'status' => $job->status,
            'prompt' => $prompt,
            'idempotency_key' => $key,
            'error_code' => $job->error_code,
            'error_message' => $job->error_message,
        ]);

        $metadata = is_array($job->provider_metadata) ? $job->provider_metadata : [];
        $metadata['audio_id'] = $audio->uuid;
        $metadata['audio_role'] = $role->value;
        if ($version instanceof StorySceneVersion) {
            $metadata['version_id'] = $version->uuid;
        }
        $job->forceFill(['provider_metadata' => $metadata])->save();

        $audio = $this->syncFromJob($project, $audio->fresh() ?? $audio);

        return $this->payload($audio, $started['created']);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(Project $project, string $reelUuid, string $sceneUuid, string $audioUuid): array
    {
        $audio = $this->findAudio($project, $reelUuid, $sceneUuid, $audioUuid);
        $audio = $this->syncFromJob($project, $audio);

        return $this->payload($audio);
    }

    public function file(Project $project, string $reelUuid, string $sceneUuid, string $audioUuid): StreamedResponse
    {
        $audio = $this->syncFromJob(
            $project,
            $this->findAudio($project, $reelUuid, $sceneUuid, $audioUuid),
        );

        if (! $audio->hasPrivateFile()) {
            throw StoryVideoEngineException::invalidInput('No private audio file is stored for this record.');
        }

        $disk = (string) $audio->disk;
        $path = (string) $audio->path;
        if ($disk !== 'voice' || str_contains($path, '..') || str_contains($path, '://')) {
            throw StoryVideoEngineException::invalidInput('The stored audio path is invalid.');
        }
        if (! Storage::disk($disk)->exists($path)) {
            throw StoryVideoEngineException::invalidInput('The stored audio file is missing.');
        }

        return Storage::disk($disk)->response($path, basename($path), [
            'Content-Type' => (string) ($audio->mime ?? 'audio/mpeg'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function roles(): array
    {
        return array_map(
            static fn (StoryAudioRole $role): array => [
                'role' => $role->value,
                'label' => $role->label(),
            ],
            StoryAudioRole::cases(),
        );
    }

    private function syncFromJob(Project $project, StorySceneAudio $audio): StorySceneAudio
    {
        $job = $audio->job;
        if (! $job instanceof StoryVideoGenerationJob) {
            $audio->loadMissing('job');
            $job = $audio->job;
        }
        if (! $job instanceof StoryVideoGenerationJob) {
            return $audio;
        }

        if ($audio->hasPrivateFile()
            && $audio->statusEnum() === StoryVideoJobStatus::Completed
            && is_string($audio->disk)
            && is_string($audio->path)
            && Storage::disk($audio->disk)->exists($audio->path)
        ) {
            return $audio;
        }

        $job = $this->dispatch->refresh($project, $job);
        $storage = is_array($job->provider_metadata['storage'] ?? null)
            ? $job->provider_metadata['storage']
            : null;

        $audio->forceFill([
            'status' => $job->status,
            'story_video_generation_job_id' => $job->id,
            'error_code' => $job->error_code,
            'error_message' => $job->error_message,
            'disk' => is_array($storage) ? ($storage['disk'] ?? null) : $audio->disk,
            'path' => is_array($storage) ? ($storage['path'] ?? null) : $audio->path,
            'mime' => is_array($storage) ? ($storage['mime'] ?? null) : $audio->mime,
            'size' => is_array($storage) ? ($storage['size'] ?? null) : $audio->size,
            'checksum' => is_array($storage) ? ($storage['checksum'] ?? null) : $audio->checksum,
        ])->save();

        return $audio->fresh() ?? $audio;
    }

    private function findAudio(
        Project $project,
        string $reelUuid,
        string $sceneUuid,
        string $audioUuid,
    ): StorySceneAudio {
        $scene = $this->scenes->getForProjectReel($project, $reelUuid, $sceneUuid);
        $audio = StorySceneAudio::query()
            ->where('story_scene_id', $scene->id)
            ->where('uuid', $audioUuid)
            ->first();

        if (! $audio instanceof StorySceneAudio) {
            throw StoryException::notFound('Scene audio');
        }

        $audio->loadMissing('workspace');
        if ($audio->workspace === null || (int) $audio->workspace->project_id !== (int) $project->id) {
            throw StoryException::notFound('Scene audio');
        }

        return $audio;
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

    private function latestSceneVersion(StoryScene $scene): ?StorySceneVersion
    {
        return StorySceneVersion::query()
            ->where('story_scene_id', $scene->id)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(StorySceneAudio $audio, ?bool $created = null): array
    {
        $audio->loadMissing(['scene', 'version', 'job']);
        $role = $audio->roleEnum();
        $data = [
            'id' => $audio->uuid,
            'role' => $audio->role,
            'role_label' => $role?->label() ?? $audio->role,
            'status' => $audio->status,
            'prompt' => $audio->prompt,
            'scene_id' => $audio->scene?->uuid,
            'version_id' => $audio->version?->uuid,
            'job_id' => $audio->job?->uuid,
            'has_file' => $audio->hasPrivateFile()
                && is_string($audio->disk)
                && is_string($audio->path)
                && Storage::disk($audio->disk)->exists($audio->path),
            'output_url' => null,
            'error_code' => $audio->error_code,
            'error_message' => $audio->error_message,
            'completed' => $audio->statusEnum() === StoryVideoJobStatus::Completed,
        ];

        if ($created !== null) {
            $data['created'] = $created;
        }

        return $data;
    }
}
