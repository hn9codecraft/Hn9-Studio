<?php

declare(strict_types=1);

namespace App\Story\Video\Adapters;

use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Enums\StoryVideoInputType;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Video\StoryVideoGenerationRequest;

/**
 * Luma Agents API (https://docs.agents.lumalabs.ai), model ray-3.2.
 *
 *  - Create:  POST {base}/generations  type "video" (text, start_frame) or "video_edit" (source)
 *  - Status:  GET  {base}/generations/{id}  state queued | processing | completed | failed
 *  - Extend:  type "video" with video.start_frame.generation_id of a completed Luma generation
 *
 * Duration is 5s or 10s; start frames only work with 5s. Edit sources must be
 * 18 seconds or shorter. There is no cancel endpoint and no callback.
 */
final class LumaStoryVideoAdapter extends HttpStoryVideoAdapter
{
    public const VENDOR = 'luma';

    private const MAX_IMAGE_BYTES = 50 * 1024 * 1024;

    private const MAX_EDIT_BYTES = 200 * 1024 * 1024;

    private const MAX_PROMPT = 6000;

    private const RATIOS = ['9:16', '3:4', '1:1', '4:3', '16:9', '21:9'];

    public function vendor(): string
    {
        return self::VENDOR;
    }

    public function supportedDurations(StoryVideoCapability $capability): array
    {
        if (in_array($capability, [StoryVideoCapability::ImageToVideo, StoryVideoCapability::VideoExtend], true)) {
            return $this->supports($capability) ? [5] : [];
        }

        return parent::supportedDurations($capability);
    }

    public function maxDurationSeconds(StoryVideoCapability $capability): ?int
    {
        if (in_array($capability, [StoryVideoCapability::ImageToVideo, StoryVideoCapability::VideoExtend], true)) {
            return $this->supports($capability) ? 5 : null;
        }

        return parent::maxDurationSeconds($capability);
    }

    protected function createTask(StoryVideoGenerationRequest $request): array
    {
        $body = match ($request->capability) {
            StoryVideoCapability::VideoEdit => $this->editBody($request),
            default => $this->videoBody($request),
        };

        $json = $this->send('POST', '/generations', $body);
        $id = $json['id'] ?? null;

        return ['id' => is_string($id) ? $id : '', 'model' => $body['model']];
    }

    protected function fetchTask(string $operationId): array
    {
        return $this->send('GET', '/generations/'.rawurlencode($operationId));
    }

    protected function cancelTask(string $operationId): void
    {
        // The Agents API has no cancel endpoint; the local job is cancelled only.
    }

    protected function interpretTask(array $task): StoryVideoTaskState
    {
        $state = strtolower((string) ($task['state'] ?? ''));

        return match ($state) {
            'queued' => new StoryVideoTaskState(StoryVideoTaskState::PENDING),
            'processing' => new StoryVideoTaskState(StoryVideoTaskState::RUNNING),
            'completed' => new StoryVideoTaskState(StoryVideoTaskState::SUCCEEDED, videoUrl: $this->videoUrl($task)),
            'failed' => $this->failure($task),
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
            StoryVideoCapability::VideoExtend,
        ], true)) {
            throw StoryVideoEngineException::capabilityNotAvailable('This video service cannot do that yet.');
        }

        if ($request->aspectRatio !== null && ! in_array($request->aspectRatio, self::RATIOS, true)) {
            throw StoryVideoEngineException::invalidInput('This picture shape is not supported by the video service.');
        }

        if ($request->capability === StoryVideoCapability::ImageToVideo) {
            $image = $this->storedMedia($request, [StoryVideoInputType::Image, StoryVideoInputType::ReferenceImage], 'images')[0] ?? null;
            if ($image !== null && strlen($image['bytes']) > self::MAX_IMAGE_BYTES) {
                throw StoryVideoEngineException::invalidInput('The picture is larger than 50 MB.');
            }
        }

        if ($request->capability === StoryVideoCapability::VideoEdit && $this->sourceGeneration($request) === null) {
            $video = $this->storedMedia($request, [StoryVideoInputType::Video], 'videos')[0] ?? null;
            if ($video !== null && strlen($video['bytes']) > self::MAX_EDIT_BYTES) {
                throw StoryVideoEngineException::invalidInput('This scene video is too large to edit with this service.');
            }
        }

        if ($request->capability === StoryVideoCapability::VideoExtend && $this->sourceGeneration($request) === null) {
            throw StoryVideoEngineException::invalidInput('This service can only extend videos it created itself.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function videoBody(StoryVideoGenerationRequest $request): array
    {
        $video = [
            'resolution' => $this->resolution($request),
            'duration' => $this->duration($request),
        ];

        if ($request->capability === StoryVideoCapability::ImageToVideo) {
            $image = $this->storedMedia($request, [StoryVideoInputType::Image, StoryVideoInputType::ReferenceImage], 'images')[0];
            $video['start_frame'] = [
                'data' => base64_encode($image['bytes']),
                'media_type' => $image['mime'],
            ];
            $video['duration'] = '5s';
        }

        if ($request->capability === StoryVideoCapability::VideoExtend) {
            $video['start_frame'] = ['generation_id' => (string) $this->sourceGeneration($request)];
            $video['duration'] = '5s';
        }

        $body = [
            'model' => $this->modelFor($request),
            'type' => 'video',
            'prompt' => $this->prompt($request),
            'video' => $video,
        ];
        if ($request->aspectRatio !== null && $request->capability !== StoryVideoCapability::VideoExtend) {
            $body['aspect_ratio'] = $request->aspectRatio;
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function editBody(StoryVideoGenerationRequest $request): array
    {
        $generation = $this->sourceGeneration($request);
        if ($generation !== null) {
            $source = ['generation_id' => $generation];
        } else {
            $video = $this->storedMedia($request, [StoryVideoInputType::Video], 'videos')[0];
            $source = [
                'data' => base64_encode($video['bytes']),
                'media_type' => str_starts_with($video['mime'], 'video/') ? $video['mime'] : 'video/mp4',
            ];
        }

        return [
            'model' => $this->modelFor($request),
            'type' => 'video_edit',
            'prompt' => $this->prompt($request),
            'source' => $source,
            'video' => [
                'resolution' => $this->resolution($request),
                'edit' => ['auto_controls' => true],
            ],
        ];
    }

    /**
     * A source video that this provider produced can be referenced by its
     * generation id instead of re-uploading the bytes.
     */
    private function sourceGeneration(StoryVideoGenerationRequest $request): ?string
    {
        foreach ($request->inputs as $input) {
            if ($input->type !== StoryVideoInputType::Video) {
                continue;
            }
            $provider = $input->metadata['provider_key'] ?? null;
            $operation = $input->metadata['operation_id'] ?? null;
            if ($provider === $this->key() && is_string($operation) && preg_match('/^[0-9a-f-]{36}$/i', $operation) === 1) {
                return $operation;
            }
        }

        return null;
    }

    private function prompt(StoryVideoGenerationRequest $request): string
    {
        $prompt = trim((string) $request->prompt);
        if ($prompt === '' && $request->capability === StoryVideoCapability::ImageToVideo) {
            $prompt = 'Bring this picture to life with natural motion.';
        }

        return mb_substr($prompt, 0, self::MAX_PROMPT);
    }

    private function duration(StoryVideoGenerationRequest $request): string
    {
        return (int) ($request->durationSeconds ?? 5) >= 10 ? '10s' : '5s';
    }

    private function resolution(StoryVideoGenerationRequest $request): string
    {
        $resolution = $request->resolution ?: (string) ($this->config['resolution'] ?? '720p');

        return in_array($resolution, ['360p', '540p', '720p', '1080p'], true) ? $resolution : '720p';
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function videoUrl(array $task): ?string
    {
        foreach ((array) ($task['output'] ?? []) as $output) {
            if (is_array($output) && ($output['type'] ?? 'video') === 'video' && is_string($output['url'] ?? null)) {
                return $output['url'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function failure(array $task): StoryVideoTaskState
    {
        $code = strtolower((string) ($task['failure_code'] ?? ''));

        [$normalized, $message] = match ($code) {
            'content_moderated' => [StoryVideoErrorCode::InvalidInput, 'The video service declined this request under its content rules. Try changing the description or picture.'],
            'budget_exhausted' => [StoryVideoErrorCode::QuotaExceeded, 'The video service account has no remaining credit.'],
            'rate_limited' => [StoryVideoErrorCode::RateLimited, 'The video service was too busy to finish this video.'],
            'image_too_large', 'unsupported_format', 'corrupt_input', 'invalid_request' => [StoryVideoErrorCode::InvalidInput, 'The video service could not use the provided picture or video.'],
            default => [StoryVideoErrorCode::UpstreamError, 'The video service could not finish this video.'],
        };

        return new StoryVideoTaskState(StoryVideoTaskState::FAILED, errorCode: $normalized, errorMessage: $message);
    }
}
