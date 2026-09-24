<?php

declare(strict_types=1);

namespace App\Services;

use App\AI\Contracts\ProviderDispatcherInterface;
use App\AI\Exceptions\NoProviderAvailableException;
use App\AI\Exceptions\ProviderNotConfiguredException;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\AI\Execution\DispatchOptions;
use App\AI\Requests\VideoRequest;
use App\AI\Responses\VideoResponse;
use App\AI\Support\Capability;
use App\Contracts\Logging\ActivityLoggerInterface;
use App\Contracts\Services\ImageServiceInterface;
use App\Contracts\Services\ScriptServiceInterface;
use App\Contracts\Services\VideoGenerationServiceInterface;
use App\Contracts\Storage\StorageInterface;
use App\Enums\ProjectStatus;
use App\Enums\VideoSource;
use App\Enums\VideoStatus;
use App\Exceptions\VideoGenerationException;
use App\Exceptions\VideoWorkflowException;
use App\Jobs\PollVideoGenerationJob;
use App\Models\GeneratedAsset;
use App\Models\Image;
use App\Models\MediaFile;
use App\Models\Project;
use App\Models\Script;
use App\Models\User;
use App\Models\Video;
use App\Repositories\Contracts\VideoRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Studio video generation. Starts a real Veo long-running operation through
 * ProviderDispatcher and never marks a video completed until the provider
 * reports done and the file is stored on the videos disk.
 */
final readonly class VideoGenerationService implements VideoGenerationServiceInterface
{
    /**
     * @var list<string>
     */
    private const VIDEO_PROVIDERS = ['gemini'];

    /**
     * @var list<string>
     */
    private const GENERATION_ASPECTS = ['16:9', '9:16'];

    /**
     * @var list<string>
     */
    private const GENERATION_RESOLUTIONS = ['720p', '1080p', '4k'];

    private const GENERATION_DURATION = 8;

    /**
     * @var list<string>
     */
    private const RELATIONS = [
        'project',
        'script',
        'image',
        'parentVideo',
        'generatedAsset',
        'file',
        'latestReviewEvent.user',
        'latestReworkEvent.user',
    ];

    public function __construct(
        private ProviderDispatcherInterface $dispatcher,
        private VideoRepositoryInterface $videos,
        private ScriptServiceInterface $scripts,
        private ImageServiceInterface $images,
        private VideoBinaryStore $binaries,
        private StorageInterface $storage,
        private ActivityLoggerInterface $activity,
    ) {}

    public function generate(Project $project, array $input, ?User $causer = null, ?Video $parent = null): array
    {
        $this->assertProjectEditable($project);

        if ($parent !== null && ! $parent->allowsRegeneration()) {
            throw VideoWorkflowException::invalidTransition(
                $parent->uuid,
                'regenerated',
                $parent->statusEnum()->value,
            );
        }

        $prompt = $this->resolvePrompt($project, $input);
        $aspectRatio = $this->aspectRatio($input, $parent);
        $resolution = $this->resolution($input);
        $provider = $this->nullableString($input['provider'] ?? null);
        $model = $this->nullableString($input['model'] ?? null);
        $pinned = $this->assertSupported($provider, $model);
        $script = $this->scriptFor($project, $input);
        $image = $this->imageFor($project, $input);
        $this->assertNoDuplicateInFlight($project, $prompt, $image, $parent);

        $options = [];
        $imagePayload = $image === null ? null : $this->imagePayload($image);

        if ($imagePayload !== null) {
            $options['image'] = $imagePayload;
        }

        $request = new VideoRequest(
            prompt: $prompt,
            model: $model,
            durationSeconds: (float) self::GENERATION_DURATION,
            resolution: $resolution,
            aspectRatio: $aspectRatio,
            options: $options,
        );

        $dispatchOptions = $pinned !== null
            ? DispatchOptions::only($pinned)
            : DispatchOptions::make();

        try {
            $result = $this->dispatcher->dispatch($request, $dispatchOptions);
        } catch (NoProviderAvailableException $exception) {
            throw $this->mapUnavailable($exception);
        } catch (UnsupportedCapabilityException $exception) {
            throw VideoGenerationException::unsupported(
                is_string($exception->context()['provider'] ?? null) ? (string) $exception->context()['provider'] : $pinned,
                $model,
            );
        }

        $response = $result->response;

        if (! $response instanceof VideoResponse) {
            throw VideoGenerationException::retrievalFailed();
        }

        $jobId = is_string($response->jobId) && $response->jobId !== '' ? $response->jobId : null;

        if ($jobId === null) {
            throw VideoGenerationException::retrievalFailed('The video provider did not return an operation id.');
        }

        $usage = $response->usage?->toArray();
        $dispatch = [
            'provider' => $result->providerKey,
            'modality' => $result->modality->value,
            'model' => $response->model,
            'duration_ms' => $result->durationMs,
            'retries' => $result->retries,
            'accepted' => true,
            'done' => false,
            'usage' => $usage,
            'cost' => $usage['cost'] ?? null,
        ];

        $video = DB::transaction(function () use ($project, $prompt, $aspectRatio, $resolution, $input, $script, $image, $parent, $dispatch, $response, $jobId, $usage, $model, $causer): Video {
            $created = $this->videos->create([
                'project_id' => $project->getKey(),
                'title' => $this->nullableString($input['title'] ?? null) ?? $this->titleFrom($prompt),
                'prompt' => $prompt,
                'negative_prompt' => $this->nullableString($input['negative_prompt'] ?? null),
                'aspect_ratio' => $aspectRatio,
                'duration' => self::GENERATION_DURATION,
                'status' => VideoStatus::Processing->value,
                'source' => VideoSource::Ai->value,
                'script_id' => $script?->getKey(),
                'image_id' => $image?->getKey(),
                'parent_video_id' => $parent?->getKey(),
                'provider' => $dispatch['provider'],
                'provider_job_id' => $jobId,
                'output_url' => null,
                'metadata' => null,
                'generation' => [
                    'provider' => $dispatch['provider'],
                    'model' => $model ?? $response->model,
                    'prompt' => $prompt,
                    'aspect_ratio' => $aspectRatio,
                    'duration' => self::GENERATION_DURATION,
                    'resolution' => $resolution,
                    'mode' => $image === null ? 'text_to_video' : 'image_to_video',
                    'parent_video_id' => $parent?->uuid,
                    'script_id' => $script?->uuid,
                    'image_id' => $image?->uuid,
                    'usage' => $usage,
                    'cost' => $dispatch['cost'],
                    'started_at' => now()->toIso8601String(),
                    'last_polled_at' => null,
                    'poll_count' => 0,
                ],
            ]);

            return $created;
        });

        $this->activity->log(
            $parent === null ? 'video.generation_started' : 'video.regeneration_started',
            $video,
            $causer,
            $parent === null ? 'AI video generation started' : 'AI video variation started',
            [
                'parent_video_id' => $parent?->uuid,
                'provider' => $dispatch['provider'],
            ],
        );

        PollVideoGenerationJob::dispatch($video->getKey());

        $refreshed = $this->refresh($video->fresh() ?? $video);

        return [
            'video' => $refreshed->load(self::RELATIONS),
            'dispatch' => [
                ...$dispatch,
                'done' => $refreshed->statusEnum() === VideoStatus::Completed,
            ],
        ];
    }

    public function refresh(Video $video): Video
    {
        $status = $video->statusEnum();

        if (! $status->isInFlight()) {
            return $video->loadMissing(self::RELATIONS);
        }

        if (! is_string($video->provider_job_id) || $video->provider_job_id === '') {
            return $this->markFailed($video, 'The provider operation id is missing.');
        }

        $generation = is_array($video->generation) ? $video->generation : [];
        $timeout = max(1, (int) config('hn9.video.timeout_seconds', 900));
        $started = $video->created_at;

        if ($started !== null && $started->lt(now()->subSeconds($timeout))) {
            return $this->markFailed($video, 'The video provider did not finish before the timeout.');
        }

        $interval = max(1, (int) config('hn9.video.poll_interval_seconds', 5));
        $lastPolled = isset($generation['last_polled_at']) && is_string($generation['last_polled_at'])
            ? $generation['last_polled_at']
            : null;

        if ($lastPolled !== null && now()->lt(\Illuminate\Support\Carbon::parse($lastPolled)->addSeconds($interval))) {
            return $video->loadMissing(self::RELATIONS);
        }

        $model = is_string($generation['model'] ?? null) ? (string) $generation['model'] : null;
        $provider = is_string($video->provider) && $video->provider !== '' ? $video->provider : 'gemini';

        try {
            $result = $this->dispatcher->dispatch(
                new VideoRequest(
                    prompt: $video->prompt,
                    model: $model,
                    options: ['operation' => $video->provider_job_id],
                ),
                DispatchOptions::only($provider),
            );
        } catch (Throwable $exception) {
            $generation['last_polled_at'] = now()->toIso8601String();
            $generation['poll_count'] = ((int) ($generation['poll_count'] ?? 0)) + 1;
            $generation['last_error'] = $exception->getMessage();
            $this->videos->update($video, ['generation' => $generation]);

            if ($started !== null && $started->lt(now()->subSeconds($timeout))) {
                return $this->markFailed($video->fresh() ?? $video, 'The video provider did not finish before the timeout.');
            }

            return ($video->fresh() ?? $video)->loadMissing(self::RELATIONS);
        }

        $response = $result->response;

        if (! $response instanceof VideoResponse) {
            return $this->markFailed($video, 'The video provider returned an unexpected response.');
        }

        $generation['last_polled_at'] = now()->toIso8601String();
        $generation['poll_count'] = ((int) ($generation['poll_count'] ?? 0)) + 1;

        if (is_string($response->error) && $response->error !== '' && $response->done) {
            return $this->markFailed($video, $response->error, $generation);
        }

        if (! $response->done) {
            $this->videos->update($video, [
                'status' => VideoStatus::Processing->value,
                'generation' => $generation,
            ]);

            return ($video->fresh() ?? $video)->loadMissing(self::RELATIONS);
        }

        if ($response->video === '') {
            return $this->markFailed($video, 'The video provider completed without a video URI.', $generation);
        }

        $project = $video->project ?? $video->project()->first();

        if ($project === null) {
            return $this->markFailed($video, 'The video project is missing.', $generation);
        }

        try {
            $stored = $this->binaries->retrieveAndStore($project, $provider, $response->video);
        } catch (VideoGenerationException $exception) {
            return $this->markFailed($video, $exception->getMessage(), $generation);
        }

        return $this->complete($video, $stored, $response, $generation);
    }

    public function storedFile(Video $video): ?MediaFile
    {
        $file = $video->file ?? $video->file()->first();

        return $file instanceof MediaFile ? $file : null;
    }

    /**
     * @param  array<string, mixed>  $generation
     */
    private function complete(Video $video, StoredVideo $stored, VideoResponse $response, array $generation): Video
    {
        return DB::transaction(function () use ($video, $stored, $response, $generation): Video {
            $usage = $response->usage?->toArray();
            $generation['completed_at'] = now()->toIso8601String();
            $generation['usage'] = $usage ?? ($generation['usage'] ?? null);
            $generation['cost'] = $usage['cost'] ?? ($generation['cost'] ?? null);
            $generation['model'] = $response->model ?? ($generation['model'] ?? null);

            $asset = GeneratedAsset::query()->create([
                'project_id' => $video->project_id,
                'type' => 'video',
                'provider' => $video->provider,
                'status' => 'completed',
                'prompt' => $video->prompt,
                'metadata' => array_filter([
                    'mime_type' => $stored->mimeType,
                    'extension' => $stored->extension,
                    'size' => $stored->size,
                    'duration' => $video->duration,
                ], static fn (mixed $value): bool => $value !== null),
            ]);

            $updated = $this->videos->update($video, [
                'status' => VideoStatus::Completed->value,
                'generated_asset_id' => $asset->getKey(),
                'output_url' => null,
                'metadata' => [
                    'mime_type' => $stored->mimeType,
                    'extension' => $stored->extension,
                    'size' => $stored->size,
                ],
                'generation' => [
                    ...$generation,
                    'generated_asset_id' => $asset->uuid,
                ],
            ]);

            $file = new MediaFile([
                'disk' => $stored->disk,
                'path' => $stored->path,
                'original_name' => 'video.'.$stored->extension,
                'mime_type' => $stored->mimeType,
                'extension' => $stored->extension,
                'size' => $stored->size,
                'checksum' => $stored->checksum,
                'collection' => 'videos',
                'meta' => [
                    'duration' => $updated->duration,
                ],
            ]);
            $file->mediable()->associate($updated);
            $file->save();

            $this->activity->log('video.generation_completed', $updated, null, 'AI video stored after provider completion');

            return $updated->load(self::RELATIONS);
        });
    }

    /**
     * @param  array<string, mixed>|null  $generation
     */
    private function markFailed(Video $video, string $message, ?array $generation = null): Video
    {
        $payload = is_array($generation) ? $generation : (is_array($video->generation) ? $video->generation : []);
        $payload['failed_at'] = now()->toIso8601String();
        $payload['error'] = $message;

        $updated = $this->videos->update($video, [
            'status' => VideoStatus::Failed->value,
            'generation' => $payload,
        ]);

        $this->activity->log('video.generation_failed', $updated, null, 'AI video generation failed');

        return $updated->load(self::RELATIONS);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolvePrompt(Project $project, array $input): string
    {
        $prompt = $this->nullableString($input['prompt'] ?? null);

        if ($prompt !== null) {
            return $prompt;
        }

        $script = $this->scriptFor($project, $input);
        $body = $this->nullableString($script?->body);

        if ($body === null) {
            throw VideoGenerationException::promptRequired();
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function scriptFor(Project $project, array $input): ?Script
    {
        $uuid = $this->nullableString($input['script_id'] ?? null);

        if ($uuid === null) {
            return null;
        }

        $script = $this->scripts->getByUuid($uuid);

        if ($script->project_id !== $project->getKey()) {
            abort(404);
        }

        return $script;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function imageFor(Project $project, array $input): ?Image
    {
        $uuid = $this->nullableString($input['image_id'] ?? null);

        if ($uuid === null) {
            return null;
        }

        $image = $this->images->getByUuid($uuid);

        if ($image->project_id !== $project->getKey()) {
            abort(404);
        }

        $file = $image->file ?? $image->file()->first();

        if (! $file instanceof MediaFile || ! $this->storage->exists($file->disk, $file->path)) {
            throw VideoGenerationException::sourceImageUnavailable($image->uuid);
        }

        return $image;
    }

    /**
     * @return array{mimeType: string, bytesBase64Encoded: string}
     */
    private function imagePayload(Image $image): array
    {
        $file = $image->file ?? $image->file()->first();

        if (! $file instanceof MediaFile) {
            throw VideoGenerationException::sourceImageUnavailable($image->uuid);
        }

        $bytes = $this->storage->get($file->disk, $file->path);

        if (! is_string($bytes) || $bytes === '') {
            throw VideoGenerationException::sourceImageUnavailable($image->uuid);
        }

        $mime = is_string($file->mime_type) && str_starts_with($file->mime_type, 'image/')
            ? $file->mime_type
            : 'image/png';

        return [
            'mimeType' => $mime,
            'bytesBase64Encoded' => base64_encode($bytes),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function aspectRatio(array $input, ?Video $parent): string
    {
        $value = $this->nullableString($input['aspect_ratio'] ?? null) ?? $parent?->aspect_ratio ?? '16:9';

        if (! in_array($value, self::GENERATION_ASPECTS, true)) {
            throw VideoGenerationException::invalidOption('aspect_ratio', 'Gemini Veo accepts 16:9 or 9:16 only.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolution(array $input): ?string
    {
        $value = $this->nullableString($input['resolution'] ?? null);

        if ($value === null) {
            return null;
        }

        if (! in_array($value, self::GENERATION_RESOLUTIONS, true)) {
            throw VideoGenerationException::invalidOption('resolution', 'Gemini Veo accepts 720p, 1080p or 4k only.');
        }

        return $value;
    }

    private function assertSupported(?string $provider, ?string $model): ?string
    {
        if ($provider !== null && ! in_array($provider, self::VIDEO_PROVIDERS, true)) {
            throw VideoGenerationException::unsupported($provider, $model);
        }

        if ($provider !== null) {
            $models = $this->configuredVideoModels($provider);

            if ($models === [] || ($model !== null && ! in_array($model, $models, true))) {
                throw VideoGenerationException::unsupported($provider, $model);
            }

            return $provider;
        }

        if ($model === null) {
            $matches = [];

            foreach (self::VIDEO_PROVIDERS as $key) {
                if ($this->configuredVideoModels($key) !== []) {
                    $matches[] = $key;
                }
            }

            return count($matches) === 1 ? $matches[0] : null;
        }

        $matches = [];

        foreach (self::VIDEO_PROVIDERS as $key) {
            if (in_array($model, $this->configuredVideoModels($key), true)) {
                $matches[] = $key;
            }
        }

        if (count($matches) !== 1) {
            throw VideoGenerationException::unsupported(null, $model);
        }

        return $matches[0];
    }

    /**
     * @return list<string>
     */
    private function configuredVideoModels(string $provider): array
    {
        $settings = config("ai.providers.{$provider}");

        if (! is_array($settings) || ! ($settings['enabled'] ?? false)) {
            return [];
        }

        $models = $settings['video_models'] ?? [];

        return is_array($models) ? array_values(array_filter($models, 'is_string')) : [];
    }

    private function assertProjectEditable(Project $project): void
    {
        $status = ProjectStatus::tryFrom((string) $project->status);

        if ($status === null || ! $status->isEditable()) {
            throw VideoGenerationException::projectNotEditable($project->uuid);
        }
    }

    private function assertNoDuplicateInFlight(Project $project, string $prompt, ?Image $image, ?Video $parent): void
    {
        $existing = Video::query()
            ->where('project_id', $project->getKey())
            ->where('source', VideoSource::Ai->value)
            ->whereIn('status', [VideoStatus::Pending->value, VideoStatus::Processing->value])
            ->where('prompt', $prompt)
            ->where('image_id', $image?->getKey())
            ->where('parent_video_id', $parent?->getKey())
            ->where('created_at', '>=', now()->subMinutes(2))
            ->latest('id')
            ->first();

        if ($existing !== null) {
            throw VideoGenerationException::inProgress($existing->uuid);
        }
    }

    private function mapUnavailable(NoProviderAvailableException $exception): NoProviderAvailableException|ProviderNotConfiguredException|VideoGenerationException
    {
        $rejected = $exception->context()['rejected'] ?? null;

        if (is_array($rejected) && $rejected === []) {
            return ProviderNotConfiguredException::forCapability(Capability::Video);
        }

        return $exception;
    }

    private function titleFrom(string $prompt): string
    {
        $line = trim(strtok($prompt, "\n") ?: $prompt);

        if ($line === '') {
            return 'AI generated video';
        }

        return mb_strlen($line) > 120 ? mb_substr($line, 0, 117).'...' : $line;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
