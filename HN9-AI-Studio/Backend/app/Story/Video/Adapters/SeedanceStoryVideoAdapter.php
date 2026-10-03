<?php

declare(strict_types=1);

namespace App\Story\Video\Adapters;

use App\Story\Enums\StoryVideoCapability;
use App\Story\Enums\StoryVideoErrorCode;
use App\Story\Enums\StoryVideoInputType;
use App\Story\Exceptions\StoryVideoEngineException;
use App\Story\Video\StoryVideoGenerationRequest;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Seedance 2.0 through the BytePlus ModelArk video generation task API.
 *
 * Contract (official docs, checked at implementation time):
 *  - Create task:   POST   {base}/contents/generations/tasks      https://docs.byteplus.com/en/docs/ModelArk/1520757
 *  - Retrieve task: GET    {base}/contents/generations/tasks/{id} https://docs.byteplus.com/en/docs/ModelArk/1521309
 *  - Cancel task:   DELETE {base}/contents/generations/tasks/{id} (queued tasks only)
 *  - Seedance 2.0 parameters and input limits:                   https://docs.byteplus.com/en/docs/ModelArk/2291680
 *
 * Images travel as base64 data URIs. Reference videos must be reachable by
 * URL, so edit and extend are only offered when the private video disk can
 * mint temporary signed URLs. Billing is per output second on the provider
 * side; only the usage the provider reports is recorded, never an estimate.
 */
final class SeedanceStoryVideoAdapter extends HttpStoryVideoAdapter
{
    public const VENDOR = 'seedance';

    private const MAX_IMAGE_BYTES = 30 * 1024 * 1024;

    private const MAX_BODY_BYTES = 64 * 1024 * 1024;

    private const MAX_REFERENCE_IMAGES = 9;

    private const RATIOS = ['16:9', '4:3', '1:1', '3:4', '9:16', '21:9'];

    public function vendor(): string
    {
        return self::VENDOR;
    }

    /**
     * @return array{id: string, model?: string|null}
     */
    protected function createTask(StoryVideoGenerationRequest $request): array
    {
        $body = $this->payload($request);
        $encoded = json_encode($body);
        if (is_string($encoded) && strlen($encoded) > self::MAX_BODY_BYTES) {
            throw StoryVideoEngineException::invalidInput('The pictures for this video are too large to send together. Use fewer or smaller pictures.');
        }

        $json = $this->send('POST', '/contents/generations/tasks', $body);
        $id = $json['id'] ?? null;

        return [
            'id' => is_string($id) ? $id : '',
            'model' => $body['model'],
        ];
    }

    protected function fetchTask(string $operationId): array
    {
        return $this->send('GET', '/contents/generations/tasks/'.rawurlencode($operationId));
    }

    protected function cancelTask(string $operationId): void
    {
        $this->send('DELETE', '/contents/generations/tasks/'.rawurlencode($operationId));
    }

    protected function interpretTask(array $task): StoryVideoTaskState
    {
        $status = strtolower((string) ($task['status'] ?? ''));
        $usage = [];
        foreach (['completion_tokens', 'total_tokens'] as $field) {
            if (isset($task['usage'][$field]) && is_numeric($task['usage'][$field])) {
                $usage[$field] = (int) $task['usage'][$field];
            }
        }
        foreach (['duration', 'resolution', 'ratio', 'framespersecond'] as $field) {
            if (isset($task[$field]) && (is_string($task[$field]) || is_numeric($task[$field]))) {
                $usage['output_'.$field] = $task[$field];
            }
        }

        return match ($status) {
            'queued' => new StoryVideoTaskState(StoryVideoTaskState::PENDING, usage: $usage),
            'running' => new StoryVideoTaskState(StoryVideoTaskState::RUNNING, usage: $usage),
            'succeeded' => new StoryVideoTaskState(
                StoryVideoTaskState::SUCCEEDED,
                videoUrl: is_string($task['content']['video_url'] ?? null) ? $task['content']['video_url'] : null,
                usage: $usage,
            ),
            'expired' => new StoryVideoTaskState(
                StoryVideoTaskState::FAILED,
                errorCode: StoryVideoErrorCode::Timeout,
                errorMessage: 'The video service did not finish in time.',
                usage: $usage,
            ),
            'cancelled' => new StoryVideoTaskState(
                StoryVideoTaskState::FAILED,
                errorCode: StoryVideoErrorCode::UpstreamError,
                errorMessage: 'The video request was cancelled.',
                usage: $usage,
            ),
            'failed' => $this->failure($task, $usage),
            default => throw StoryVideoEngineException::provider(
                StoryVideoErrorCode::InvalidProviderResponse,
                'The video service returned an unexpected status.',
            ),
        };
    }

    protected function validateProviderRules(StoryVideoGenerationRequest $request): void
    {
        if ($request->capability === StoryVideoCapability::Audio) {
            throw StoryVideoEngineException::invalidInput('Sound on its own is not created by this video service.');
        }

        if ($request->aspectRatio !== null && ! in_array($request->aspectRatio, self::RATIOS, true)) {
            throw StoryVideoEngineException::invalidInput('This picture shape is not supported by the video service.');
        }

        $images = $this->storedMedia($request, [StoryVideoInputType::Image, StoryVideoInputType::ReferenceImage], 'images');
        if (count($images) > self::MAX_REFERENCE_IMAGES) {
            throw StoryVideoEngineException::invalidInput('Use at most nine reference pictures for one video.');
        }
        foreach ($images as $image) {
            if (strlen($image['bytes']) >= self::MAX_IMAGE_BYTES) {
                throw StoryVideoEngineException::invalidInput('One of the pictures is larger than 30 MB.');
            }
        }

        $resolution = $this->resolution($request);
        if ($request->capability === StoryVideoCapability::ReferenceToVideo && in_array($resolution, ['1080p', '4k'], true)) {
            throw StoryVideoEngineException::invalidInput('Videos made from reference pictures can be at most 720p with this service.');
        }

        if (in_array($request->capability, [StoryVideoCapability::VideoEdit, StoryVideoCapability::VideoExtend], true)
            && $this->referenceVideoUrl($request) === null) {
            throw StoryVideoEngineException::invalidInput('Editing or extending needs secure cloud storage for scene videos, which is not set up.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(StoryVideoGenerationRequest $request): array
    {
        $prompt = trim((string) $request->prompt);
        $content = [];
        if ($prompt !== '') {
            $content[] = ['type' => 'text', 'text' => $request->capability === StoryVideoCapability::VideoExtend
                ? 'Continue this video seamlessly. '.$prompt
                : $prompt];
        }

        $images = $this->storedMedia($request, [StoryVideoInputType::Image, StoryVideoInputType::ReferenceImage], 'images');
        if ($request->capability === StoryVideoCapability::ImageToVideo && $images !== []) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $this->dataUri($images[0]['bytes'], $images[0]['mime'])],
                'role' => 'first_frame',
            ];
        }
        if ($request->capability === StoryVideoCapability::ReferenceToVideo) {
            foreach ($images as $image) {
                $content[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => $this->dataUri($image['bytes'], $image['mime'])],
                    'role' => 'reference_image',
                ];
            }
        }
        if (in_array($request->capability, [StoryVideoCapability::VideoEdit, StoryVideoCapability::VideoExtend], true)) {
            $content[] = [
                'type' => 'video_url',
                'video_url' => ['url' => (string) $this->referenceVideoUrl($request)],
                'role' => 'reference_video',
            ];
        }

        $body = [
            'model' => $this->modelFor($request),
            'content' => $content,
            'resolution' => $this->resolution($request),
            'generate_audio' => $request->audioRequested,
            'watermark' => false,
        ];
        if ($request->aspectRatio !== null) {
            $body['ratio'] = $request->aspectRatio;
        } elseif ($request->capability === StoryVideoCapability::TextToVideo) {
            $body['ratio'] = '16:9';
        } else {
            $body['ratio'] = 'adaptive';
        }
        if ($request->durationSeconds !== null) {
            $body['duration'] = $request->durationSeconds;
        }

        $callback = $request->extensions['callback_url'] ?? null;
        if (is_string($callback) && str_starts_with($callback, 'https://')) {
            $body['callback_url'] = $callback;
        }

        $expires = (int) ($this->config['execution_expires_after'] ?? 0);
        if ($expires >= 3600 && $expires <= 259200) {
            $body['execution_expires_after'] = $expires;
        }

        return $body;
    }

    private function resolution(StoryVideoGenerationRequest $request): string
    {
        $resolution = $request->resolution ?: (string) ($this->config['resolution'] ?? '720p');
        $supported = $this->supportedResolutions($request->capability);

        return $supported === [] || in_array($resolution, $supported, true) ? $resolution : $supported[0];
    }

    /**
     * Seedance only reads reference videos from a URL. The private disk must
     * be able to sign a short-lived link; local disks cannot.
     */
    private function referenceVideoUrl(StoryVideoGenerationRequest $request): ?string
    {
        if (! (bool) ($this->config['signed_reference_videos'] ?? false)) {
            return null;
        }

        foreach ($request->inputs as $input) {
            if ($input->type !== StoryVideoInputType::Video) {
                continue;
            }
            $path = $input->metadata['path'] ?? null;
            if (($input->metadata['disk'] ?? null) !== 'videos' || ! is_string($path) || $path === '' || str_contains($path, '..')) {
                continue;
            }

            try {
                $url = Storage::disk('videos')->temporaryUrl($path, now()->addHours(2));
            } catch (Throwable) {
                return null;
            }

            return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  array<string, int|float|string>  $usage
     */
    private function failure(array $task, array $usage): StoryVideoTaskState
    {
        $code = strtolower((string) ($task['error']['code'] ?? ''));
        $message = (string) ($task['error']['message'] ?? '');

        [$normalized, $friendly] = match (true) {
            str_contains($code, 'sensitive') || str_contains($code, 'risk') || str_contains($code, 'moderation') => [
                StoryVideoErrorCode::InvalidInput,
                'The video service declined this request under its content rules. Try changing the description or pictures.',
            ],
            str_contains($code, 'quota') || str_contains($code, 'balance') || str_contains($code, 'arrear') => [
                StoryVideoErrorCode::QuotaExceeded,
                'The video service account has no remaining credit.',
            ],
            str_contains($code, 'ratelimit') || str_contains($code, 'rate_limit') => [
                StoryVideoErrorCode::RateLimited,
                'The video service was too busy to finish this video.',
            ],
            str_contains($code, 'invalid') || str_contains($code, 'parameter') => [
                StoryVideoErrorCode::InvalidInput,
                'The video service could not use these settings.',
            ],
            default => [StoryVideoErrorCode::UpstreamError, 'The video service could not finish this video.'],
        };

        if ($normalized === StoryVideoErrorCode::InvalidInput && $message !== '') {
            $friendly .= ' ('.mb_substr($message, 0, 160).')';
        }

        return new StoryVideoTaskState(
            StoryVideoTaskState::FAILED,
            errorCode: $normalized,
            errorMessage: $friendly,
            usage: $usage,
        );
    }
}
