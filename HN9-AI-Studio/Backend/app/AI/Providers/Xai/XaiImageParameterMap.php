<?php

declare(strict_types=1);

namespace App\AI\Providers\Xai;

/**
 * Maps this app's image request onto the xAI image generation body.
 *
 * xAI accepts aspect_ratio, resolution, quality and response_format.
 * It does not accept OpenAI size or style. Style is omitted.
 * Quality is sent only for grok-imagine-image-2.0.
 */
final class XaiImageParameterMap
{
    /** @var array<string, string> */
    private const SIZES = [
        '1024x1024' => '1:1',
        '1:1' => '1:1',
        '1536x1024' => '3:2',
        '1792x1024' => '16:9',
        '16:9' => '16:9',
        '1024x1536' => '2:3',
        '1024x1792' => '9:16',
        '9:16' => '9:16',
        '4:3' => '4:3',
        '3:4' => '3:4',
        '3:2' => '3:2',
        '2:3' => '2:3',
        '21:9' => '21:9',
        '1:2' => '1:2',
        '2:1' => '2:1',
    ];

    /**
     * @return array<string, mixed>
     */
    public function body(string $model, string $prompt, int $count, ?string $size, ?string $quality, ?string $format): array
    {
        $body = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => max(1, $count),
            'response_format' => $format === 'b64_json' ? 'b64_json' : 'url',
        ];

        $ratio = $this->aspectRatio($size);
        if ($ratio !== null) {
            $body['aspect_ratio'] = $ratio;
        }

        $resolution = $this->resolution($size);
        if ($resolution !== null) {
            $body['resolution'] = $resolution;
        }

        $mappedQuality = $this->quality($model, $quality);
        if ($mappedQuality !== null) {
            $body['quality'] = $mappedQuality;
        }

        return $body;
    }

    public function aspectRatio(?string $size): ?string
    {
        if ($size === null || trim($size) === '') {
            return null;
        }

        return self::SIZES[strtolower(trim($size))] ?? null;
    }

    public function resolution(?string $size): ?string
    {
        if ($size === null) {
            return null;
        }
        $value = strtolower($size);

        return str_contains($value, '2048') || str_contains($value, '2k') ? '2k' : null;
    }

    public function quality(string $model, ?string $quality): ?string
    {
        if ($model !== 'grok-imagine-image-2.0' || $quality === null || trim($quality) === '') {
            return null;
        }

        return match (strtolower(trim($quality))) {
            'low', 'standard' => 'low',
            'medium', 'hd', 'high' => 'medium',
            'auto' => 'auto',
            default => null,
        };
    }
}
