<?php

declare(strict_types=1);

namespace App\AI\Providers\Xai;

use App\AI\Exceptions\ProviderApiException;
use App\AI\Responses\ImageResponse;
use App\AI\Responses\TextResponse;
use App\AI\Responses\VoiceResponse;

final readonly class XaiResponseNormalizer
{
    public function __construct(
        private XaiUsageCalculator $usageCalculator,
        private XaiTimestampMapper $timestamps,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     */
    public function text(array $response, string $model, int $executionTimeMs): TextResponse
    {
        $text = $this->responseText($response);
        if ($text === null) {
            throw ProviderApiException::forProvider(XaiConfig::KEY, 'xAI response did not contain text output.');
        }
        $usage = isset($response['usage']) && is_array($response['usage'])
            ? $this->usageCalculator->fromUsage($response['usage'], $model, $executionTimeMs) : null;

        return new TextResponse(
            $text,
            (string) ($response['model'] ?? $model),
            (string) ($response['status'] ?? ''),
            $usage,
            $response,
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function image(array $response, string $model, int $executionTimeMs): ImageResponse
    {
        $data = $response['data'] ?? [];
        $images = [];
        if (is_array($data)) {
            foreach ($data as $item) {
                if (! is_array($item)) {
                    continue;
                }
                if (isset($item['url']) && is_string($item['url']) && $item['url'] !== '') {
                    $images[] = $item['url'];
                } elseif (isset($item['b64_json']) && is_string($item['b64_json']) && $item['b64_json'] !== '') {
                    $images[] = 'data:image/png;base64,'.$item['b64_json'];
                }
            }
        }
        if ($images === []) {
            throw ProviderApiException::forProvider(XaiConfig::KEY, 'xAI response did not contain image output.');
        }
        $usage = isset($response['usage']) && is_array($response['usage'])
            ? $this->usageCalculator->fromUsage($response['usage'], $model, $executionTimeMs) : null;

        return new ImageResponse($images, (string) ($response['model'] ?? $model), $usage, $response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function voice(array $response, string $voiceId, string $language, int $characters, int $executionTimeMs): VoiceResponse
    {
        $audio = $response['audio'] ?? null;
        if (! is_string($audio) || $audio === '') {
            throw ProviderApiException::forProvider(XaiConfig::KEY, 'xAI response did not contain audio output.');
        }
        $media = is_string($response['content_type'] ?? null) && $response['content_type'] !== ''
            ? $response['content_type']
            : 'audio/mpeg';
        $alignment = $this->timestamps->alignment($response);
        $duration = isset($response['duration']) && is_numeric($response['duration']) ? (float) $response['duration'] : null;

        return new VoiceResponse(
            audio: 'data:'.$media.';base64,'.$audio,
            model: 'grok-tts',
            voice: $voiceId,
            format: $media,
            durationSeconds: $duration,
            usage: null,
            raw: [
                'characters' => $characters,
                'voice_id' => $voiceId,
                'language' => $language,
                'media_type' => $media,
                'alignment' => $alignment,
                'duration' => $duration,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function responseText(array $response): ?string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }
        if (! is_array($response['output'] ?? null)) {
            return null;
        }
        foreach ($response['output'] as $output) {
            foreach ((is_array($output) ? ($output['content'] ?? []) : []) as $contentItem) {
                if (is_array($contentItem) && isset($contentItem['text']) && is_string($contentItem['text'])) {
                    return $contentItem['text'];
                }
            }
        }

        return null;
    }
}
