<?php

declare(strict_types=1);

namespace App\Story\Video\Adapters;

use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Enums\StoryVideoInputType;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Video\StoryVideoGenerationRequest;

/**
 * xAI video API (https://docs.x.ai/developers/model-capabilities/video/generation).
 *
 *  - Create: POST /v1/videos/generations
 *  - Poll:   GET  /v1/videos/{request_id}
 *  - Status: pending | done | failed | expired
 *
 * Clips are requested with generate_audio false. Assembly also drops any
 * audio track that still arrives. No cancel endpoint is documented.
 */
final class XaiStoryVideoAdapter extends HttpStoryVideoAdapter
{
    public const VENDOR = 'xai';

    public function vendor(): string
    {
        return self::VENDOR;
    }

    public function referenceImageLimit(StoryVideoCapability $capability): int
    {
        return $capability === StoryVideoCapability::ImageToVideo ? 1 : 0;
    }

    protected function createTask(StoryVideoGenerationRequest $request): array
    {
        $body = $request->capability === StoryVideoCapability::ImageToVideo
            ? $this->imageBody($request)
            : $this->textBody($request);
        $json = $this->send('POST', '/videos/generations', $body);
        $id = $json['request_id'] ?? null;

        return ['id' => is_string($id) ? $id : '', 'model' => $body['model']];
    }

    protected function fetchTask(string $operationId): array
    {
        return $this->send('GET', '/videos/'.rawurlencode($operationId));
    }

    protected function cancelTask(string $operationId): void
    {
        // The video generation docs list poll statuses only. There is no cancel route.
    }

    protected function interpretTask(array $task): StoryVideoTaskState
    {
        $status = strtolower((string) ($task['status'] ?? ''));
        $video = is_array($task['video'] ?? null) ? $task['video'] : [];
        $url = is_string($video['url'] ?? null) ? $video['url'] : null;

        return match ($status) {
            'pending' => new StoryVideoTaskState(StoryVideoTaskState::PENDING),
            'done' => new StoryVideoTaskState(StoryVideoTaskState::SUCCEEDED, videoUrl: $url, progress: 1.0),
            'failed', 'expired' => new StoryVideoTaskState(
                StoryVideoTaskState::FAILED,
                errorCode: StoryVideoErrorCode::UpstreamError,
                errorMessage: $status === 'expired'
                    ? 'The video request expired before it finished.'
                    : 'The video service could not finish this video.',
            ),
            default => throw StoryVideoEngineException::provider(
                StoryVideoErrorCode::InvalidProviderResponse,
                'The video service returned an unexpected status.',
            ),
        };
    }

    public function validate(StoryVideoGenerationRequest $request): void
    {
        $this->catalog->validate($request);
        if (trim((string) $request->prompt) === '' && $request->capability !== StoryVideoCapability::ImageToVideo) {
            throw StoryVideoEngineException::invalidInput('Describe what should happen in this video.');
        }
        $this->validateProviderRules($request);
    }

    protected function validateProviderRules(StoryVideoGenerationRequest $request): void
    {
        if (! in_array($request->capability, [
            StoryVideoCapability::TextToVideo,
            StoryVideoCapability::ImageToVideo,
        ], true)) {
            throw StoryVideoEngineException::capabilityNotAvailable('This video service cannot do that yet.');
        }

        $seconds = (int) ($request->durationSeconds ?? 10);
        if ($seconds < 1 || $seconds > 15) {
            throw StoryVideoEngineException::invalidInput('This video service accepts clips from 1 to 15 seconds.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function textBody(StoryVideoGenerationRequest $request): array
    {
        return [
            'model' => $this->modelFor($request),
            'prompt' => $this->prompt($request),
            'duration' => $this->duration($request),
            'aspect_ratio' => $request->aspectRatio ?? '16:9',
            'resolution' => $this->resolution($request),
            'generate_audio' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function imageBody(StoryVideoGenerationRequest $request): array
    {
        $image = $this->storedMedia($request, [StoryVideoInputType::Image, StoryVideoInputType::ReferenceImage], 'images')[0] ?? null;
        if ($image === null) {
            throw StoryVideoEngineException::invalidInput('This video needs a picture to start from.');
        }

        return [
            'model' => $this->modelFor($request),
            'prompt' => $this->prompt($request),
            'image' => ['url' => $this->dataUri($image['bytes'], $image['mime'])],
            'duration' => $this->duration($request),
            'resolution' => $this->resolution($request),
            'generate_audio' => false,
        ];
    }

    private function prompt(StoryVideoGenerationRequest $request): string
    {
        return trim((string) $request->prompt);
    }

    private function duration(StoryVideoGenerationRequest $request): int
    {
        return max(1, min(15, (int) ($request->durationSeconds ?? 10)));
    }

    private function resolution(StoryVideoGenerationRequest $request): string
    {
        $requested = $request->resolution;
        if (is_string($requested) && in_array($requested, ['480p', '720p', '1080p'], true)) {
            return $requested;
        }
        $configured = $this->config['resolution'] ?? null;

        return is_string($configured) && in_array($configured, ['480p', '720p', '1080p'], true) ? $configured : '720p';
    }
}
