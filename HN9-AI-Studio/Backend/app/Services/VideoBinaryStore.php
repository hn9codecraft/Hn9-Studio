<?php

declare(strict_types=1);

namespace App\Services;

use App\AI\Providers\Gemini\GeminiClient;
use App\AI\Providers\Gemini\GeminiConfig;
use App\AI\Support\ProviderConfigResolver;
use App\Contracts\Storage\StorageInterface;
use App\Exceptions\VideoGenerationException;
use App\Models\Project;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use Throwable;

/**
 * Downloads a completed Veo file through the provider client and stores it
 * on the configured videos disk. Temporary provider URIs are not kept.
 */
final readonly class VideoBinaryStore
{
    private const MAX_BYTES = 104_857_600;

    public function __construct(
        private StorageInterface $storage,
        private ProviderConfigResolver $resolver,
        private HttpFactory $http,
    ) {}

    public function retrieveAndStore(Project $project, string $provider, string $uri): StoredVideo
    {
        if ($uri === '') {
            throw VideoGenerationException::retrievalFailed();
        }

        try {
            $bytes = $this->download($provider, $uri);
        } catch (VideoGenerationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw VideoGenerationException::retrievalFailed($exception->getMessage());
        }

        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw VideoGenerationException::retrievalFailed();
        }

        $mime = $this->detectMime($bytes);
        $extension = $this->extensionFor($mime);
        $path = $project->uuid.'/'.Str::uuid()->toString().'.'.$extension;

        try {
            $this->storage->put('videos', $path, $bytes);
        } catch (Throwable $exception) {
            throw VideoGenerationException::storageFailed($exception);
        }

        if (! $this->storage->exists('videos', $path)) {
            throw VideoGenerationException::storageFailed();
        }

        return new StoredVideo(
            disk: 'videos',
            path: $path,
            mimeType: $mime,
            extension: $extension,
            size: strlen($bytes),
            checksum: hash('sha256', $bytes),
        );
    }

    private function download(string $provider, string $uri): string
    {
        if ($provider !== 'gemini') {
            throw VideoGenerationException::retrievalFailed();
        }

        $config = GeminiConfig::fromProviderConfig($this->resolver->resolve('gemini'));
        $client = new GeminiClient($this->http, $config);

        return $client->download($uri);
    }

    private function detectMime(string $bytes): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);

        if (is_string($mime) && str_starts_with($mime, 'video/')) {
            return $mime;
        }

        return 'video/mp4';
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            default => 'mp4',
        };
    }
}
