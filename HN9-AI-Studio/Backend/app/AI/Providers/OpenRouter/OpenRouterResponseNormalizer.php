<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenRouter;

use App\AI\DTOs\ProviderResponseDTO;
use App\AI\Exceptions\ProviderApiException;
use App\AI\Responses\TextResponse;
use App\AI\Responses\TokenResponse;
use App\AI\Responses\UsageResponse;

/**
 * Translates OpenRouter payloads into the shared response objects, so nothing
 * downstream sees a vendor shape — or learns which upstream vendor served the
 * call. A payload that cannot be understood raises a typed parsing failure
 * rather than yielding an empty result.
 *
 * Because OpenRouter normalises many vendors onto one envelope, message content
 * arrives either as a plain string or as a list of typed parts; both are
 * accepted. The full payload is retained on the response so router specifics
 * (the serving vendor, the upstream finish reason, the generation id) remain
 * available for telemetry without widening the shared contract.
 */
final readonly class OpenRouterResponseNormalizer
{
    public function __construct(private OpenRouterUsageCalculator $usage) {}

    /**
     * @param  array<string, mixed>  $response
     * @param  string  $model  The resolved model, which prices the call.
     */
    public function text(array $response, string $model, int $executionTimeMs): TextResponse
    {
        $choice = $this->firstChoice($response);
        $text = $this->content($choice);

        if ($text === null || $text === '') {
            throw ProviderApiException::forProvider(
                OpenRouterConfig::KEY,
                'OpenRouter response did not contain text output.',
            );
        }

        return new TextResponse(
            $text,
            $this->model($response, $model),
            $this->finishReason($choice),
            $this->usageFor($response, $model, $executionTimeMs),
            $response,
        );
    }

    /**
     * The vendor's authoritative token count for a completed call.
     *
     * @param  array<string, mixed>  $response
     */
    public function tokens(array $response, string $model): TokenResponse
    {
        $usage = $response['usage'] ?? null;
        $total = is_array($usage) ? ($usage['total_tokens'] ?? null) : null;

        if (! is_int($total) && ! (is_string($total) && is_numeric($total))) {
            throw ProviderApiException::forProvider(
                OpenRouterConfig::KEY,
                'OpenRouter response did not contain a token count.',
            );
        }

        return new TokenResponse((int) $total, $model);
    }

    public function envelope(TextResponse $response): ProviderResponseDTO
    {
        return ProviderResponseDTO::success($response, OpenRouterConfig::KEY, $response->usage);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function firstChoice(array $response): array
    {
        $choices = $response['choices'] ?? null;

        if (! is_array($choices)) {
            return [];
        }

        return array_values(array_filter($choices, 'is_array'))[0] ?? [];
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function content(array $choice): ?string
    {
        $content = $choice['message']['content'] ?? $choice['text'] ?? null;

        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return null;
        }

        // Some routed vendors answer with typed content parts rather than a string.
        $text = '';

        foreach ($content as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function model(array $response, string $requested): string
    {
        $reported = $response['model'] ?? null;

        return is_string($reported) && $reported !== '' ? $reported : $requested;
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function finishReason(array $choice): ?string
    {
        $reason = $choice['finish_reason'] ?? $choice['native_finish_reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function usageFor(array $response, string $model, int $executionTimeMs): ?UsageResponse
    {
        $usage = $response['usage'] ?? null;

        return is_array($usage) ? $this->usage->fromUsage($usage, $model, $executionTimeMs) : null;
    }
}
