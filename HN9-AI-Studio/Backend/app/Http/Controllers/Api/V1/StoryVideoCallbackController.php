<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Story\Models\StoryVideoGenerationJob;
use App\Story\Services\StoryVideoJobRunner;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Receives status callbacks from video providers. The payload is never
 * trusted: a valid per-job token only prompts the server to check the task
 * with the provider again. The response is identical whatever happens so the
 * endpoint reveals nothing about jobs.
 */
final class StoryVideoCallbackController extends Controller
{
    public function __invoke(string $jobUuid, string $token, StoryVideoJobRunner $runner): JsonResponse
    {
        $job = StoryVideoGenerationJob::query()->where('uuid', $jobUuid)->first();
        $hash = $job !== null ? (string) data_get($job->provider_metadata, 'callback_token_hash', '') : '';

        if ($job !== null && $hash !== '' && hash_equals($hash, hash('sha256', $token))) {
            $primary = data_get($job->provider_metadata, 'primary_job');
            $target = is_string($primary)
                ? StoryVideoGenerationJob::query()
                    ->where('uuid', $primary)
                    ->where('story_workspace_id', $job->story_workspace_id)
                    ->first()
                : $job;

            if ($target !== null) {
                try {
                    $runner->advance($target);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return response()->json(['received' => true]);
    }
}
