<?php

declare(strict_types=1);

namespace App\AI\Providers\Gemini;

use App\AI\DTOs\ProviderResponseDTO;
use App\AI\Exceptions\ProviderApiException;
use App\AI\Responses\ImageResponse;
use App\AI\Responses\TextResponse;
use App\AI\Responses\TokenResponse;
use App\AI\Responses\UsageResponse;

/**
 * Translates Gemini payloads into the shared response objects so controllers
 * and services never see a vendor shape. A payload that cannot be understood
 * raises a typed parsing failure rather than yielding an empty result.
 */
final readonly class GeminiResponseNormalizer
{
    private const DEFAULT_IMAGE_MIME_TYPE = 'image/png';

    public function __construct(private GeminiUsageCalculator $usage) {}

    /**
     * @param  array<string, mixed>  $response
     */
    public function text(array $response, string $model, int $executionTimeMs): TextResponse
    {
        $text = '';

        foreach ($this->parts($response) as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        if ($text === '') {
            throw ProviderApiException::forProvider('gemini', 'Gemini response did not contain text output.');
        }

        return new TextResponse(
            $text,
            $this->model($response, $model),
            $this->finishReason($response),
            $this->usageFor($response, $model, $executionTimeMs),
            $response,
        );
    }

    /**
     * Inline image bytes are surfaced as data URIs, matching how the abstraction
     * carries references rather than binary payloads.
     *
     * @param  array<string, mixed>  $response
     */
    public function image(array $response, string $model, int $executionTimeMs): ImageResponse
    {
        $images = [];

        foreach ($this->parts($response) as $part) {
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;

            if (! is_array($inline) || ! isset($inline['data']) || ! is_string($inline['data']) || $inline['data'] === '') {
                continue;
            }

            $mimeType = $inline['mimeType'] ?? $inline['mime_type'] ?? null;
            $images[] = 'data:'.(is_string($mimeType) && $mimeType !== '' ? $mimeType : self::DEFAULT_IMAGE_MIME_TYPE)
                .';base64,'.$inline['data'];
        }

        if ($images === []) {
            throw ProviderApiException::forProvider('gemini', 'Gemini response did not contain image output.');
        }

        return new ImageResponse($images, $this->model($response, $model), $this->usageFor($response, $model, $executionTimeMs), $response);
    }

    /**
     * @param  array<string, mixed>  $response  A `countTokens` payload.
     */
    public function tokens(array $response, string $model): TokenResponse
    {
        $total = $response['totalTokens'] ?? null;

        if (! is_int($total) && ! (is_string($total) && is_numeric($total))) {
            throw ProviderApiException::forProvider('gemini', 'Gemini response did not contain a token count.');
        }

        return new TokenResponse((int) $total, $model);
    }

    public function envelope(TextResponse|ImageResponse $response): ProviderResponseDTO
    {
        return ProviderResponseDTO::success($response, 'gemini', $response->usage);
    }

    /**
     * Every content part across every returned candidate.
     *
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function parts(array $response): array
    {
        $parts = [];

        foreach ($this->candidates($response) as $candidate) {
            $content = $candidate['content'] ?? null;

            if (! is_array($content) || ! is_array($content['parts'] ?? null)) {
                continue;
            }

            foreach ($content['parts'] as $part) {
                if (is_array($part)) {
                    $parts[] = $part;
                }
            }
        }

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function candidates(array $response): array
    {
        if (! is_array($response['candidates'] ?? null)) {
            return [];
        }

        return array_values(array_filter($response['candidates'], 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function model(array $response, string $requested): string
    {
        $reported = $response['modelVersion'] ?? null;

        return is_string($reported) && $reported !== '' ? $reported : $requested;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function finishReason(array $response): ?string
    {
        $reason = $this->candidates($response)[0]['finishReason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function usageFor(array $response, string $model, int $executionTimeMs): ?UsageResponse
    {
        $metadata = $response['usageMetadata'] ?? null;

        return is_array($metadata) ? $this->usage->fromUsageMetadata($metadata, $model, $executionTimeMs) : null;
    }
}
