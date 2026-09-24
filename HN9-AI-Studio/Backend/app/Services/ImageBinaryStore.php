<?php

declare(strict_types=1);

namespace App\Services;

use App\AI\Responses\ImageResponse;
use App\Contracts\Storage\StorageInterface;
use App\Exceptions\ImageGenerationException;
use App\Models\Project;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a provider image reference into a file on the configured images disk.
 * Temporary provider URLs are downloaded here and are not kept as the asset.
 */
final readonly class ImageBinaryStore
{
    private const MAX_BYTES = 20_971_520;

    public function __construct(
        private StorageInterface $storage,
        private HttpFactory $http,
    ) {}

    public function store(Project $project, ImageResponse $response): StoredImage
    {
        $reference = $response->images[0] ?? null;

        if (! is_string($reference) || $reference === '') {
            throw ImageGenerationException::malformed();
        }

        try {
            [$bytes, $declaredMime] = str_starts_with($reference, 'data:')
                ? $this->fromDataUri($reference)
                : $this->fromUrl($reference);
        } catch (ImageGenerationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ImageGenerationException::malformed();
        }

        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw ImageGenerationException::malformed();
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false) {
            throw ImageGenerationException::malformed();
        }

        $mime = is_string($info['mime'] ?? null) && str_starts_with($info['mime'], 'image/')
            ? $info['mime']
            : $declaredMime;

        if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
            throw ImageGenerationException::malformed();
        }

        $extension = $this->extensionFor($mime);
        $path = $project->uuid.'/'.Str::uuid()->toString().'.'.$extension;

        try {
            $this->storage->put('images', $path, $bytes);
        } catch (Throwable $exception) {
            throw ImageGenerationException::storageFailed($exception);
        }

        if (! $this->storage->exists('images', $path)) {
            throw ImageGenerationException::storageFailed();
        }

        $width = is_int($info[0] ?? null) ? $info[0] : null;
        $height = is_int($info[1] ?? null) ? $info[1] : null;

        return new StoredImage(
            disk: 'images',
            path: $path,
            mimeType: $mime,
            extension: $extension,
            size: strlen($bytes),
            checksum: hash('sha256', $bytes),
            width: $width,
            height: $height,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function fromDataUri(string $reference): array
    {
        if (! preg_match('#\Adata:(image/[a-zA-Z0-9.+-]+);base64,(.+)\z#s', $reference, $matches)) {
            throw ImageGenerationException::malformed();
        }

        $bytes = base64_decode($matches[2], true);

        if (! is_string($bytes) || $bytes === '') {
            throw ImageGenerationException::malformed();
        }

        return [$bytes, strtolower($matches[1])];
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function fromUrl(string $reference): array
    {
        $this->assertPublicHttps($reference);

        $response = $this->http
            ->timeout(20)
            ->withOptions(['allow_redirects' => false])
            ->accept('image/*')
            ->get($reference);

        if (! $response->successful()) {
            throw ImageGenerationException::malformed();
        }

        $bytes = $response->body();
        $header = $response->header('Content-Type');
        $mime = is_string($header) ? strtolower(trim(strtok($header, ';') ?: '')) : null;

        return [$bytes, is_string($mime) && str_starts_with($mime, 'image/') ? $mime : null];
    }

    private function assertPublicHttps(string $url): void
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if ($scheme !== 'https' || ! is_string($host) || $host === '') {
            throw ImageGenerationException::malformed();
        }

        $host = strtolower($host);

        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw ImageGenerationException::malformed();
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $public = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

            if ($public === false) {
                throw ImageGenerationException::malformed();
            }
        }
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };
    }
}
