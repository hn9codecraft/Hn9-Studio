<?php

declare(strict_types=1);

namespace App\Story\Services;

use App\AI\Responses\ImageResponse;
use App\Contracts\Storage\StorageInterface;
use App\Models\Project;
use App\Story\Exceptions\StoryStyleException;
use App\Story\Models\StoryStyleBible;
use App\Story\Support\StoredStyleReference;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

/**
 * Private storage for style reference images. Paths never leave the service layer in API responses.
 */
final readonly class StyleReferenceBinaryStore
{
    private const MAX_BYTES = 10_485_760;

    private const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private StorageInterface $storage,
        private HttpFactory $http,
    ) {}

    public function storeUpload(Project $project, StoryStyleBible $style, UploadedFile $file): StoredStyleReference
    {
        if (! $file->isValid()) {
            throw StoryStyleException::invalidImage();
        }

        if ($file->getSize() !== false && $file->getSize() > self::MAX_BYTES) {
            throw StoryStyleException::invalidImage();
        }

        $bytes = @file_get_contents($file->getRealPath() ?: '');

        if (! is_string($bytes) || $bytes === '') {
            throw StoryStyleException::invalidImage();
        }

        return $this->storeBytes($project, $style, $bytes, $file->getMimeType(), $file->getClientOriginalName());
    }

    public function storeGenerated(Project $project, StoryStyleBible $style, ImageResponse $response): StoredStyleReference
    {
        $reference = $response->images[0] ?? null;

        if (! is_string($reference) || $reference === '') {
            throw StoryStyleException::generationFailed('The image provider returned no image.');
        }

        try {
            [$bytes, $declaredMime] = str_starts_with($reference, 'data:')
                ? $this->fromDataUri($reference)
                : $this->fromUrl($reference);
        } catch (StoryStyleException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw StoryStyleException::generationFailed('The image provider returned an unreadable image.');
        }

        return $this->storeBytes($project, $style, $bytes, $declaredMime, null);
    }

    public function delete(string $disk, string $path): void
    {
        try {
            $this->storage->delete($disk, $path);
        } catch (Throwable) {
            // Best-effort cleanup.
        }
    }

    public function get(string $disk, string $path): ?string
    {
        return $this->storage->get($disk, $path);
    }

    public function exists(string $disk, string $path): bool
    {
        return $this->storage->exists($disk, $path);
    }

    private function storeBytes(
        Project $project,
        StoryStyleBible $style,
        string $bytes,
        ?string $declaredMime,
        ?string $originalFilename,
    ): StoredStyleReference {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw StoryStyleException::invalidImage();
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false) {
            throw StoryStyleException::invalidImage();
        }

        $mime = is_string($info['mime'] ?? null) && isset(self::ALLOWED_MIMES[$info['mime']])
            ? $info['mime']
            : null;

        if ($mime === null && is_string($declaredMime) && isset(self::ALLOWED_MIMES[$declaredMime])) {
            $mime = $declaredMime;
        }

        if ($mime === null) {
            throw StoryStyleException::invalidImage();
        }

        $extension = self::ALLOWED_MIMES[$mime];
        $path = sprintf(
            '%s/story-styles/%s/%s.%s',
            $project->uuid,
            $style->uuid,
            (string) Str::uuid(),
            $extension,
        );

        try {
            $this->storage->put('images', $path, $bytes);
        } catch (Throwable $exception) {
            throw StoryStyleException::storageFailed($exception->getMessage());
        }

        if (! $this->storage->exists('images', $path)) {
            throw StoryStyleException::storageFailed();
        }

        return new StoredStyleReference(
            disk: 'images',
            path: $path,
            mimeType: $mime,
            extension: $extension,
            size: strlen($bytes),
            checksum: hash('sha256', $bytes),
            width: is_int($info[0] ?? null) ? $info[0] : null,
            height: is_int($info[1] ?? null) ? $info[1] : null,
            originalFilename: $originalFilename,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function fromDataUri(string $reference): array
    {
        if (! preg_match('#\Adata:(image/[a-zA-Z0-9.+-]+);base64,(.+)\z#s', $reference, $matches)) {
            throw StoryStyleException::generationFailed('The image provider returned a malformed data URI.');
        }

        $bytes = base64_decode($matches[2], true);

        if (! is_string($bytes) || $bytes === '') {
            throw StoryStyleException::generationFailed('The image provider returned empty image data.');
        }

        return [$bytes, strtolower($matches[1])];
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function fromUrl(string $reference): array
    {
        if (! preg_match('#\Ahttps://#i', $reference)) {
            throw StoryStyleException::generationFailed('The image provider returned an unsafe image URL.');
        }

        $response = $this->http
            ->timeout(30)
            ->withOptions(['allow_redirects' => false])
            ->accept('image/*')
            ->get($reference);

        if (! $response->successful()) {
            throw StoryStyleException::generationFailed('Failed to download the generated image.');
        }

        $bytes = $response->body();
        $mime = $response->header('Content-Type');

        return [$bytes, is_string($mime) ? strtolower(trim(explode(';', $mime)[0])) : null];
    }
}
