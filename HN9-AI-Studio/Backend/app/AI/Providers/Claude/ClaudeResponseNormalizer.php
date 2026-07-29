<?php

declare(strict_types=1);

namespace App\AI\Providers\Claude;

use App\AI\DTOs\ProviderResponseDTO;
use App\AI\Exceptions\ProviderApiException;
use App\AI\Responses\TextResponse;

final readonly class ClaudeResponseNormalizer
{
    public function __construct(private ClaudeUsageCalculator $usage) {}

    /** @param array<string, mixed> $response */
    public function text(array $response, string $model, int $executionTimeMs): TextResponse
    {
        $text = '';
        foreach (($response['content'] ?? []) as $content) {
            if (is_array($content) && ($content['type'] ?? null) === 'text' && is_string($content['text'] ?? null)) {
                $text .= $content['text'];
            }
        }
        if ($text === '') {
            throw ProviderApiException::forProvider('claude', 'Claude response did not contain text output.');
        }
        $usage = is_array($response['usage'] ?? null) ? $this->usage->fromUsage($response['usage'], $model, $executionTimeMs) : null;

        return new TextResponse($text, (string) ($response['model'] ?? $model), is_string($response['stop_reason'] ?? null) ? $response['stop_reason'] : null, $usage, $response);
    }

    public function envelope(TextResponse $response): ProviderResponseDTO
    {
        return ProviderResponseDTO::success($response, 'claude', $response->usage);
    }
}
