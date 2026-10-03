<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\Contracts\Logging\ActivityLoggerInterface;
use App\Models\Project;
use App\Models\User;
use App\Story\Contracts\StoryAudioVoiceCatalogInterface;
use App\Story\Contracts\StorySceneServiceInterface;
use App\Story\Enums\StoryAudioRole;
use App\Story\Enums\StoryReviewStatus;
use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Enums\StoryVideoJobStatus;
use App\Story\Exceptions\StoryException;
use App\Story\Exceptions\StoryReviewException;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StorySceneAudio;
use App\Story\Models\StorySceneVersion;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Video\StoryVideoGenerationRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Scene audio studio. Roles are request data; vendors stay inside adapters.
 * Every generation is a new version; an approved version is never changed by later work.
 */
final readonly class StoryAudioService
{
    public const EVENT_VERSION_CREATED = 'story.sound.version_created';

    public const EVENT_REWORKED = 'story.sound.reworked';

    public const EVENT_APPROVED = 'story.sound.approved';

    public const EVENT_CHANGES_REQUESTED = 'story.sound.changes_requested';

    public const EVENT_SELECTED = 'story.sound.selected';

    public function __construct(
        private StorySceneServiceInterface $scenes,
        private StoryVideoDispatchService $dispatch,
        private StoryTimelineService $timelines,
        private ActivityLoggerInterface $activity,
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
            ->with(['scene', 'version', 'job', 'parentAudio'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (StorySceneAudio $audio): array => $this->payload($audio))
            ->all();
    }

    /**
     * Generates a new sound version. Empty text falls back to the scene's own narration or dialogue.
     *
     * @return array<string, mixed>
     */
    public function create(
        Project $project,
        string $reelUuid,
        string $sceneUuid,
        StoryAudioRole $role,
        ?string $prompt,
        ?string $versionUuid = null,
        ?string $voice = null,
        ?User $actor = null,
    ): array {
        $this->dispatch->assertLive($project, StoryVideoCapability::Audio, $reelUuid, $sceneUuid);
        $this->assertRole($role);

        $scene = $this->scenes->getForProjectReel($project, $reelUuid, $sceneUuid);
        $scene->loadMissing('reel.workspace');
        $version = $versionUuid !== null
            ? $this->sceneVersion($scene, $versionUuid)
            : $this->latestSceneVersion($scene);

        $prompt = $this->spokenText($scene, $role, $prompt);
        $voice = $this->voice($voice);
        $key = 'audio:'.$scene->uuid.':'.$role->value.':'.hash('sha256', $prompt).($voice === null ? '' : ':'.$voice);

        return $this->generate(
            $project,
            $reelUuid,
            $scene,
            $role,
            $prompt,
            $voice,
            $key,
            $version,
            $this->latestForRole($scene, $role),
            self::EVENT_VERSION_CREATED,
            $actor,
        );
    }

    /**
     * Makes a new version from an existing one. The source version, approved or not, stays as it is.
     *
     * @return array<string, mixed>
     */
    public function rework(
        Project $project,
        string $reelUuid,
        string $sceneUuid,
        string $audioUuid,
        ?string $prompt,
        ?string $voice,
        ?User $actor,
    ): array {
        $this->dispatch->assertLive($project, StoryVideoCapability::Audio, $reelUuid, $sceneUuid);

        $source = $this->syncFromJob($project, $this->findAudio($project, $reelUuid, $sceneUuid, $audioUuid));
        if (! in_array($source->statusEnum(), [StoryVideoJobStatus::Completed, StoryVideoJobStatus::Failed, StoryVideoJobStatus::Cancelled], true)) {
            throw StoryReviewException::sound('This sound is still being made. Wait for it to finish first.');
        }

        $role = $source->roleEnum();
        if (! $role instanceof StoryAudioRole) {
            throw StoryVideoEngineException::invalidInput('This sound type is not supported.');
        }
        $this->assertRole($role);

        $scene = $source->scene ?? $this->scenes->getForProjectReel($project, $reelUuid, $sceneUuid);
        $scene->loadMissing('reel.workspace');
        $text = trim((string) $prompt);
        $text = $text !== '' ? $text : trim((string) $source->prompt);
        $text = $this->spokenText($scene, $role, $text);
        $voice = $this->voice($voice);
        $key = 'audio-rework:'.$source->uuid.':'.hash('sha256', $text).($voice === null ? '' : ':'.$voice);

        return $this->generate(
            $project,
            $reelUuid,
            $scene,
            $role,
            $text,
            $voice,
            $key,
            $source->version,
            $source,
            self::EVENT_REWORKED,
            $actor,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(
        Project $project,
        string $reelUuid,
        string $sceneUuid,
        string $audioUuid,
        User $actor,
        ?string $comment = null,
    ): array {
        $audio = $this->syncFromJob($project, $this->findAudio($project, $reelUuid, $sceneUuid, $audioUuid));
        $status = $audio->reviewStatusEnum();
        if ($status === StoryReviewStatus::Approved) {
            return $this->payload($audio, false);
        }
        $this->assertReviewable($audio, 'approved');

        DB::transaction(function () use ($audio, $actor, $comment): void {
            $audio->forceFill([
                'review_status' => StoryReviewStatus::Approved->value,
                'review_comment' => $this->comment($comment) ?? $audio->review_comment,
                'reviewed_at' => now(),
                'reviewed_by' => $actor->id,
            ])->save();
            $this->markSelected($audio);
        });

        $this->log(self::EVENT_APPROVED, $audio, $actor, 'Sound approved', ['from_status' => $status->value]);
        $this->log(self::EVENT_SELECTED, $audio, $actor, 'Sound version selected');
        $this->timelines->placeApprovedSceneAudio($project, $audio);

        return $this->payload($audio->fresh() ?? $audio, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function requestChanges(
        Project $project,
        string $reelUuid,
        string $sceneUuid,
        string $audioUuid,
        User $actor,
        string $comment,
    ): array {
        $comment = $this->comment($comment);
        if ($comment === null) {
            throw StoryVideoEngineException::invalidInput('Say what should change.');
        }

        $audio = $this->syncFromJob($project, $this->findAudio($project, $reelUuid, $sceneUuid, $audioUuid));
        $status = $audio->reviewStatusEnum();
        if ($status === StoryReviewStatus::NeedsRework) {
            return $this->payload($audio, false);
        }
        if ($status === StoryReviewStatus::Approved) {
            throw StoryReviewException::sound('This sound is already approved. Make a new version to change it.');
        }
        $this->assertReviewable($audio, 'changed');

        $audio->forceFill([
            'review_status' => StoryReviewStatus::NeedsRework->value,
            'review_comment' => $comment,
            'reviewed_at' => now(),
            'reviewed_by' => $actor->id,
        ])->save();

        $this->log(self::EVENT_CHANGES_REQUESTED, $audio, $actor, 'Changes requested on sound', [
            'from_status' => $status->value,
            'comment' => $comment,
        ]);

        return $this->payload($audio->fresh() ?? $audio, true);
    }

    /**
     * Makes an approved version the one the timeline uses.
     *
     * @return array<string, mixed>
     */
    public function select(Project $project, string $reelUuid, string $sceneUuid, string $audioUuid, User $actor): array
    {
        $audio = $this->findAudio($project, $reelUuid, $sceneUuid, $audioUuid);
        if ($audio->reviewStatusEnum() !== StoryReviewStatus::Approved || ! $this->fileExists($audio)) {
            throw StoryReviewException::sound('Only an approved sound can be used in the video.');
        }
        if ($audio->isSelected()) {
            return $this->payload($audio, false);
        }

        DB::transaction(fn () => $this->markSelected($audio));
        $this->log(self::EVENT_SELECTED, $audio, $actor, 'Sound version selected');
        $this->timelines->placeApprovedSceneAudio($project, $audio);

        return $this->payload($audio->fresh() ?? $audio, true);
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

    public static function versionLabel(int $number): string
    {
        $number = max(1, $number);
        $label = '';
        while ($number > 0) {
            $number--;
            $label = chr(65 + ($number % 26)).$label;
            $number = intdiv($number, 26);
        }

        return 'Version '.$label;
    }

    /**
     * @return array<string, mixed>
     */
    private function generate(
        Project $project,
        string $reelUuid,
        StoryScene $scene,
        StoryAudioRole $role,
        string $prompt,
        ?string $voice,
        string $key,
        ?StorySceneVersion $version,
        ?StorySceneAudio $parent,
        string $event,
        ?User $actor,
    ): array {
        $workspaceId = (int) $scene->reel->story_workspace_id;
        $existing = $this->byKey($workspaceId, $key);
        if ($existing instanceof StorySceneAudio) {
            return $this->payload($this->syncFromJob($project, $existing), false);
        }

        try {
            $audio = DB::transaction(function () use ($workspaceId, $scene, $role, $prompt, $key, $version, $parent): StorySceneAudio {
                $next = (int) StorySceneAudio::query()
                    ->where('story_scene_id', $scene->id)
                    ->where('role', $role->value)
                    ->lockForUpdate()
                    ->max('version_number') + 1;

                return StorySceneAudio::query()->create([
                    'story_workspace_id' => $workspaceId,
                    'story_scene_id' => $scene->id,
                    'story_scene_version_id' => $version?->id,
                    'role' => $role->value,
                    'version_number' => $next,
                    'parent_audio_id' => $parent?->id,
                    'status' => StoryVideoJobStatus::Queued->value,
                    'review_status' => StoryReviewStatus::Draft->value,
                    'prompt' => $prompt,
                    'idempotency_key' => $key,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $existing = $this->byKey($workspaceId, $key);
            if ($existing instanceof StorySceneAudio) {
                return $this->payload($this->syncFromJob($project, $existing), false);
            }
            throw StoryVideoEngineException::invalidInput('This sound is already being made.');
        }

        $this->log($event, $audio, $actor, $event === self::EVENT_REWORKED ? 'Sound reworked' : 'Sound version created', [
            'version' => self::versionLabel((int) $audio->version_number),
            'role' => $role->value,
            'from_version' => $parent instanceof StorySceneAudio ? self::versionLabel((int) $parent->version_number) : null,
        ]);

        try {
            $started = $this->dispatch->start($project, new StoryVideoGenerationRequest(
                capability: StoryVideoCapability::Audio,
                reelUuid: $reelUuid,
                sceneUuid: $scene->uuid,
                prompt: $prompt,
                durationSeconds: 8,
                idempotencyKey: $key,
                metadata: array_filter([
                    'audio_role' => $role->value,
                    'audio_id' => $audio->uuid,
                    'version_id' => $version?->uuid,
                    'voice' => $voice,
                    'scene_title' => $scene->title,
                    'characters' => $this->characters($scene),
                ], static fn ($value): bool => $value !== null && $value !== '' && $value !== []),
            ));
        } catch (Throwable $exception) {
            $this->markFailed($audio, $exception, $workspaceId, $key);
            throw $exception;
        }

        $job = $started['job'];
        $audio->forceFill([
            'story_video_generation_job_id' => $job->id,
            'status' => $job->status,
            'error_code' => $job->error_code,
            'error_message' => $job->error_message,
        ])->save();

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

    private function markFailed(StorySceneAudio $audio, Throwable $exception, int $workspaceId, string $key): void
    {
        $job = StoryVideoGenerationJob::query()
            ->where('story_workspace_id', $workspaceId)
            ->where('idempotency_key', $key)
            ->first();

        $audio->forceFill([
            'story_video_generation_job_id' => $job?->id,
            'status' => StoryVideoJobStatus::Failed->value,
            'error_code' => $exception instanceof StoryVideoEngineException
                ? $exception->errorCode()
                : StoryVideoErrorCode::UnknownProviderError->value,
            'error_message' => $exception instanceof StoryVideoEngineException
                ? $exception->getMessage()
                : 'Sound generation failed. Try again.',
        ])->save();
    }

    private function markSelected(StorySceneAudio $audio): void
    {
        StorySceneAudio::query()
            ->where('story_scene_id', $audio->story_scene_id)
            ->where('role', $audio->role)
            ->whereKeyNot($audio->id)
            ->whereNotNull('selected_at')
            ->update(['selected_at' => null]);

        $audio->forceFill(['selected_at' => now()])->save();
    }

    private function assertReviewable(StorySceneAudio $audio, string $action): void
    {
        if (! $this->fileExists($audio) || $audio->statusEnum() !== StoryVideoJobStatus::Completed) {
            throw StoryReviewException::sound('This sound is not ready for review yet.');
        }
        if (! $audio->reviewStatusEnum()->isReviewable()) {
            throw StoryReviewException::sound(
                $action === 'approved'
                    ? 'Changes were requested on this sound. Make a new version or approve another one.'
                    : 'This sound cannot be changed right now.',
            );
        }
    }

    private function assertRole(StoryAudioRole $role): void
    {
        $liveRoles = $this->dispatch->liveAudioRoles();
        if ($liveRoles !== [] && ! in_array($role->value, $liveRoles, true)) {
            throw StoryVideoEngineException::invalidInput(
                'The requested audio role is not supported. No alternate role was substituted.',
            );
        }
    }

    /**
     * Uses the typed text, else the scene's narration or dialogue for the role.
     */
    private function spokenText(StoryScene $scene, StoryAudioRole $role, ?string $prompt): string
    {
        $text = trim((string) $prompt);
        if ($text === '') {
            $narration = trim((string) $scene->narration);
            $dialogue = $this->dialogue($scene);
            $text = match ($role) {
                StoryAudioRole::Dialogue => $dialogue ?: $narration,
                default => $narration ?: $dialogue,
            };
        }
        if ($text === '') {
            throw StoryVideoEngineException::invalidInput('Write the words that should be spoken.');
        }

        return $text;
    }

    private function dialogue(StoryScene $scene): string
    {
        $lines = [];
        foreach ((array) ($scene->dialogue ?? []) as $line) {
            if (is_string($line)) {
                $lines[] = trim($line);
            } elseif (is_array($line)) {
                $speaker = trim((string) ($line['character'] ?? $line['speaker'] ?? ''));
                $words = trim((string) ($line['line'] ?? $line['text'] ?? ''));
                $lines[] = $speaker !== '' && $words !== '' ? $speaker.': '.$words : $words;
            }
        }

        return trim(implode("\n", array_filter($lines, static fn (string $line): bool => $line !== '')));
    }

    /**
     * @return list<string>
     */
    private function characters(StoryScene $scene): array
    {
        return array_values(array_filter(
            array_map(
                static fn ($character): string => is_string($character)
                    ? trim($character)
                    : (is_array($character) ? trim((string) ($character['name'] ?? '')) : ''),
                (array) ($scene->characters ?? []),
            ),
            static fn (string $name): bool => $name !== '',
        ));
    }

    /**
     * A chosen voice must be one the connected sound service lists; no other voice is substituted.
     */
    private function voice(?string $voice): ?string
    {
        $voice = trim((string) $voice);
        if ($voice === '') {
            return null;
        }

        $adapter = $this->dispatch->liveAdapterFor(StoryVideoCapability::Audio);
        if ($adapter instanceof StoryAudioVoiceCatalogInterface) {
            foreach ($adapter->voiceNames() as $name) {
                if (strcasecmp($name, $voice) === 0) {
                    return $name;
                }
            }

            throw StoryVideoEngineException::invalidInput('Choose one of the available voices.');
        }

        return $voice;
    }

    private function comment(?string $comment): ?string
    {
        $comment = trim((string) $comment);

        return $comment === '' ? null : mb_substr($comment, 0, 2000);
    }

    private function byKey(int $workspaceId, string $key): ?StorySceneAudio
    {
        return StorySceneAudio::query()
            ->where('story_workspace_id', $workspaceId)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function latestForRole(StoryScene $scene, StoryAudioRole $role): ?StorySceneAudio
    {
        return StorySceneAudio::query()
            ->where('story_scene_id', $scene->id)
            ->where('role', $role->value)
            ->orderByDesc('version_number')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(string $action, StorySceneAudio $audio, ?User $actor, string $description, array $properties = []): void
    {
        $this->activity->log($action, $audio, $actor, $description, array_merge([
            'version' => self::versionLabel((int) $audio->version_number),
            'role' => $audio->role,
        ], array_filter($properties, static fn ($value): bool => $value !== null)));
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
        ]);
        if ($audio->reviewStatusEnum() === StoryReviewStatus::Draft
            && $audio->statusEnum() === StoryVideoJobStatus::Completed
            && $audio->hasPrivateFile()
        ) {
            $audio->review_status = StoryReviewStatus::PendingReview->value;
        }
        $audio->save();

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

    private function fileExists(StorySceneAudio $audio): bool
    {
        return $audio->hasPrivateFile()
            && is_string($audio->disk)
            && is_string($audio->path)
            && Storage::disk($audio->disk)->exists($audio->path);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(StorySceneAudio $audio, ?bool $created = null): array
    {
        $audio->loadMissing(['scene', 'version', 'job', 'parentAudio']);
        $role = $audio->roleEnum();
        $number = (int) ($audio->version_number ?: 1);
        $data = [
            'id' => $audio->uuid,
            'role' => $audio->role,
            'role_label' => $role?->label() ?? $audio->role,
            'version_number' => $number,
            'version_label' => self::versionLabel($number),
            'parent_id' => $audio->parentAudio?->uuid,
            'parent_label' => $audio->parentAudio instanceof StorySceneAudio
                ? self::versionLabel((int) $audio->parentAudio->version_number)
                : null,
            'status' => $audio->status,
            'review_status' => $audio->reviewStatusEnum()->value,
            'review_comment' => $audio->review_comment,
            'reviewed_at' => $audio->reviewed_at?->toIso8601String(),
            'selected' => $audio->isSelected(),
            'prompt' => $audio->prompt,
            'scene_id' => $audio->scene?->uuid,
            'version_id' => $audio->version?->uuid,
            'job_id' => $audio->job?->uuid,
            'has_file' => $this->fileExists($audio),
            'output_url' => null,
            'error_code' => $audio->error_code,
            'error_message' => $audio->error_message,
            'completed' => $audio->statusEnum() === StoryVideoJobStatus::Completed,
            'created_at' => $audio->created_at?->toIso8601String(),
        ];

        if ($created !== null) {
            $data['created'] = $created;
        }

        return $data;
    }
}
