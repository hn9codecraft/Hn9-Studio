<?php

declare(strict_types=1);

namespace App\Story\Video\Adapters;

use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Enums\StoryVideoInputType;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Video\StoryVideoGenerationRequest;

/**
 * Runway developer API (OpenAPI https://docs.dev.runwayml.com/openapi.json,
 * version header X-Runway-Version: 2024-11-06).
 *
 *  - Text to video:  POST /v1/text_to_video   (gen4.5: promptText, ratio, duration 2-10)
 *  - Image to video: POST /v1/image_to_video  (gen4.5: promptImage as first frame)
 *  - Edit video:     POST /v1/video_to_video  (aleph2: videoUri up to 30 seconds)
 *  - Task status:    GET  /v1/tasks/{id}      PENDING | THROTTLED | RUNNING | SUCCEEDED | FAILED | CANCELLED
 *  - Cancel:         DELETE /v1/tasks/{id}
 *
 * Media travels as data URIs (up to 5 MB each). Runway has no native extend.
 */
final class RunwayStoryVideoAdapter extends HttpStoryVideoAdapter
{
    public const VENDOR = 'runway';

    public const API_VERSION = '2024-11-06';

    private const MAX_DATA_URI_CHARS = 5_242_880;

    private const MAX_PROMPT = 1000;

    private const RATIOS = [
        '16:9' => '1280:720',
        '9:16' => '720:1280',
    ];

    public function vendor(): string
    {
        return self::VENDOR;
    }

    protected function headers(): array
    {
        return ['X-Runway-Version' => (string) ($this->config['api_version'] ?? self::API_VERSION)];
    }

    protected function createTask(StoryVideoGenerationRequest $request): array
    {
        [$path, $body] = match ($request->capability) {
            StoryVideoCapability::ImageToVideo => ['/v1/image_to_video', $this->imageBody($request)],
            StoryVideoCapability::VideoEdit => ['/v1/video_to_video', $this->editBody($request)],
            default => ['/v1/text_to_video', $this->textBody($request)],
        };

        $json = $this->send('POST', $path, $body);
        $id = $json['id'] ?? null;

        return ['id' => is_string($id) ? $id : '', 'model' => $body['model']];
    }

    protected function fetchTask(string $operationId): array
    {
        return $this->send('GET', '/v1/tasks/'.rawurlencode($operationId));
    }

    protected function cancelTask(string $operationId): void
    {
        $this->send('DELETE', '/v1/tasks/'.rawurlencode($operationId));
    }

    protected function interpretTask(array $task): StoryVideoTaskState
    {
        $status = strtoupper((string) ($task['status'] ?? ''));
        $usage = [];
        if (isset($task['cost']['credits']) && is_numeric($task['cost']['credits'])) {
            $usage['credits'] = (int) $task['cost']['credits'];
        }
        $progress = isset($task['progress']) && is_numeric($task['progress']) ? (float) $task['progress'] : null;

        return match ($status) {
            'PENDING', 'THROTTLED' => new StoryVideoTaskState(StoryVideoTaskState::PENDING, usage: $usage),
            'RUNNING' => new StoryVideoTaskState(StoryVideoTaskState::RUNNING, usage: $usage, progress: $progress),
            'SUCCEEDED' => new StoryVideoTaskState(
                StoryVideoTaskState::SUCCEEDED,
                videoUrl: is_string($task['output'][0] ?? null) ? $task['output'][0] : null,
                usage: $usage,
                progress: 1.0,
            ),
            'CANCELLED' => new StoryVideoTaskState(
                StoryVideoTaskState::FAILED,
                errorCode: StoryVideoErrorCode::UpstreamError,
                errorMessage: 'The video request was cancelled.',
                usage: $usage,
            ),
            'FAILED' => $this->failure($task, $usage),
            default => throw StoryVideoEngineException::provider(
                StoryVideoErrorCode::InvalidProviderResponse,
                'The video service returned an unexpected status.',
            ),
        };
    }

    protected function validateProviderRules(StoryVideoGenerationRequest $request): void
    {
        if (! in_array($request->capability, [
            StoryVideoCapability::TextToVideo,
            StoryVideoCapability::ImageToVideo,
            StoryVideoCapability::VideoEdit,
        ], true)) {
            throw StoryVideoEngineException::capabilityNotAvailable('This video service cannot do that yet.');
        }

        if ($request->capability !== StoryVideoCapability::VideoEdit
            && $request->aspectRatio !== null
            && ! isset(self::RATIOS[$request->aspectRatio])) {
            throw StoryVideoEngineException::invalidInput('This picture shape is not supported by the video service.');
        }

        if ($request->capability === StoryVideoCapability::ImageToVideo) {
            $image = $this->storedMedia($request, [StoryVideoInputType::Image, StoryVideoInputType::ReferenceImage], 'images')[0] ?? null;
            if ($image !== null && strlen($this->dataUri($image['bytes'], $image['mime'])) > self::MAX_DATA_URI_CHARS) {
                throw StoryVideoEngineException::invalidInput('The picture is too large for this video service (about 3.5 MB at most).');
            }
        }

        if ($request->capability === StoryVideoCapability::VideoEdit) {
            $video = $this->storedMedia($request, [StoryVideoInputType::Video], 'videos')[0] ?? null;
            if ($video !== null && strlen($this->dataUri($video['bytes'], $video['mime'])) > self::MAX_DATA_URI_CHARS) {
                throw StoryVideoEngineException::invalidInput('This scene video is too large for this video service to edit (about 3.5 MB at most).');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function textBody(StoryVideoGenerationRequest $request): array
    {
        return [
            'model' => $this->modelFor($request),
            'promptText' => $this->prompt($request),
            'ratio' => self::RATIOS[$request->aspectRatio ?? '16:9'] ?? self::RATIOS['16:9'],
            'duration' => $this->duration($request),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function imageBody(StoryVideoGenerationRequest $request): array
    {
        $image = $this->storedMedia($request, [StoryVideoInputType::Image, StoryVideoInputType::ReferenceImage], 'images')[0];
        $body = [
            'model' => $this->modelFor($request),
            'promptImage' => $this->dataUri($image['bytes'], $image['mime']),
            'ratio' => self::RATIOS[$request->aspectRatio ?? '16:9'] ?? self::RATIOS['16:9'],
            'duration' => $this->duration($request),
        ];
        $prompt = $this->prompt($request);
        if ($prompt !== '') {
            $body['promptText'] = $prompt;
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function editBody(StoryVideoGenerationRequest $request): array
    {
        $video = $this->storedMedia($request, [StoryVideoInputType::Video], 'videos')[0];

        return [
            'model' => (string) ($this->config['edit_model'] ?? 'aleph2'),
            'promptText' => $this->prompt($request),
            'videoUri' => $this->dataUri($video['bytes'], $video['mime']),
        ];
    }

    private function prompt(StoryVideoGenerationRequest $request): string
    {
        return mb_substr(trim((string) $request->prompt), 0, self::MAX_PROMPT);
    }

    private function duration(StoryVideoGenerationRequest $request): int
    {
        return max(2, min(10, (int) ($request->durationSeconds ?? 5)));
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  array<string, int|float|string>  $usage
     */
    private function failure(array $task, array $usage): StoryVideoTaskState
    {
        $code = strtoupper((string) ($task['failureCode'] ?? ''));
        $blocked = str_contains($code, 'SAFETY') || str_contains($code, 'MODERATION');
        $input = str_starts_with($code, 'INPUT');

        return new StoryVideoTaskState(
            StoryVideoTaskState::FAILED,
            errorCode: $blocked || $input ? StoryVideoErrorCode::InvalidInput : StoryVideoErrorCode::UpstreamError,
            errorMessage: match (true) {
                $blocked => 'The video service declined this request under its content rules. Try changing the description or picture.',
                $input => 'The video service could not use the provided picture or video.',
                default => 'The video service could not finish this video.',
            },
            usage: $usage,
        );
    }
}
