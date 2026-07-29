<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenAI;

use App\AI\DTOs\ProviderResponseDTO;
use App\AI\Exceptions\ProviderApiException;
use App\AI\Responses\ImageResponse;
use App\AI\Responses\TextResponse;

final readonly class OpenAIResponseNormalizer
{
    public function __construct(private OpenAIUsageCalculator $usageCalculator) {}

    /** @param array<string, mixed> $response */
    public function text(array $response, string $model, int $executionTimeMs): TextResponse
    {
        $text = $this->responseText($response);
        if ($text === null) {
            throw ProviderApiException::forProvider('openai', 'OpenAI response did not contain text output.');
        }
        $usage = isset($response['usage']) && is_array($response['usage'])
            ? $this->usageCalculator->fromUsage($response['usage'], $model, $executionTimeMs) : null;

        return new TextResponse($text, (string) ($response['model'] ?? $model), (string) ($response['status'] ?? $response['choices'][0]['finish_reason'] ?? ''), $usage, $response);
    }

    /** @param array<string, mixed> $response */
    public function image(array $response, string $model, int $executionTimeMs): ImageResponse
    {
        $data = $response['data'] ?? [];
        $images = [];
        if (is_array($data)) {
            foreach ($data as $item) {
                if (is_array($item) && isset($item['url']) && is_string($item['url'])) {
                    $images[] = $item['url'];
                }
                if (is_array($item) && isset($item['b64_json']) && is_string($item['b64_json'])) {
                    $images[] = 'data:image/png;base64,'.$item['b64_json'];
                }
            }
        }
        if ($images === []) {
            throw ProviderApiException::forProvider('openai', 'OpenAI response did not contain image output.');
        }
        $usage = isset($response['usage']) && is_array($response['usage'])
            ? $this->usageCalculator->fromUsage($response['usage'], $model, $executionTimeMs) : null;

        return new ImageResponse($images, (string) ($response['model'] ?? $model), $usage, $response);
    }

    public function envelope(TextResponse|ImageResponse $response): ProviderResponseDTO
    {
        return ProviderResponseDTO::success($response, 'openai', $response->usage);
    }

    /** @param array<string, mixed> $response */
    private function responseText(array $response): ?string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }
        $content = $response['choices'][0]['message']['content'] ?? null;
        if (is_string($content)) {
            return $content;
        }
        if (! is_array($response['output'] ?? null)) {
            return null;
        }
        foreach ($response['output'] as $output) {
            foreach ((is_array($output) ? $output['content'] ?? [] : []) as $contentItem) {
                if (is_array($contentItem) && isset($contentItem['text']) && is_string($contentItem['text'])) {
                    return $contentItem['text'];
                }
            }
        }

        return null;
    }
}
