<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\AI\Exceptions\NoProviderAvailableException;
use App\AI\Exceptions\ProviderNotConfiguredException;
use App\AI\Support\ProviderErrorSanitizer;
use App\Models\Project;
use App\Story\Models\StoryGenerationAttempt;
use App\Story\Models\StoryReel;
use App\Story\Models\StoryScene;
use App\Story\Models\StoryWorkspace;
use Throwable;

/**
 * Records generation attempts that failed before a job existed so History can show them.
 * Recording never interrupts the caller: a failure to record is swallowed.
 */
final class StoryGenerationAttemptRecorder
{
    public const KIND_VIDEO = 'video';

    public const KIND_AUDIO = 'audio';

    public const KIND_PLAN = 'plan';

    public const KIND_CHARACTER_IMAGE = 'character_image';

    public const KIND_STYLE_IMAGE = 'style_image';

    public function record(
        Project $project,
        string $kind,
        string $status,
        ?string $capability = null,
        ?string $errorCode = null,
        ?string $message = null,
        ?string $reelUuid = null,
        ?string $sceneUuid = null,
    ): void {
        try {
            $workspaceId = StoryWorkspace::query()->where('project_id', $project->id)->value('id');
            if ($workspaceId === null) {
                return;
            }

            $reelId = $reelUuid === null ? null : StoryReel::query()
                ->where('story_workspace_id', $workspaceId)
                ->where('uuid', $reelUuid)
                ->value('id');
            $sceneId = $sceneUuid === null || $reelId === null ? null : StoryScene::query()
                ->where('story_reel_id', $reelId)
                ->where('uuid', $sceneUuid)
                ->value('id');

            StoryGenerationAttempt::query()->create([
                'story_workspace_id' => $workspaceId,
                'story_reel_id' => $reelId,
                'story_scene_id' => $sceneId,
                'kind' => $kind,
                'capability' => $capability,
                'status' => $status,
                'error_code' => $errorCode === null ? null : mb_substr($errorCode, 0, 80),
                'error_message' => $message === null
                    ? null
                    : mb_substr(ProviderErrorSanitizer::message($message, 'The request failed.'), 0, 300),
            ]);
        } catch (Throwable) {
            // History is best effort; the caller's own error is what the user needs.
        }
    }

    /**
     * Records a provider failure, classifying "nothing is connected" separately from a failed call.
     */
    public function recordFailure(Project $project, string $kind, ?string $capability, Throwable $exception): void
    {
        $notConnected = $exception instanceof NoProviderAvailableException
            || $exception instanceof ProviderNotConfiguredException;

        $this->record(
            $project,
            $kind,
            $notConnected ? StoryGenerationAttempt::STATUS_NOT_CONNECTED : StoryGenerationAttempt::STATUS_FAILED,
            $capability,
            $notConnected ? 'provider_not_connected' : 'provider_failed',
            $exception->getMessage() !== '' ? $exception->getMessage() : null,
        );
    }
}
