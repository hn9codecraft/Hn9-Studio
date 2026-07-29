<?php

declare(strict_types=1);

namespace App\AI\Providers\Gemini;

use App\AI\Exceptions\AIException;
use App\AI\Responses\TokenResponse;
use App\AI\Support\AbstractTokenCounter;

/**
 * Token counting for Gemini. Unlike the other providers, Gemini publishes a real
 * tokenizer endpoint (`models/{model}:countTokens`), so exact counts are used
 * when `remote_token_counting` is enabled in configuration.
 *
 * Because counting is a preflight concern (cost estimation must not fail a
 * request that has not been made yet), a typed provider failure degrades to the
 * shared local estimate instead of propagating. Disable
 * `remote_token_counting` to keep counting entirely offline.
 */
final readonly class GeminiTokenCounter extends AbstractTokenCounter
{
    public function __construct(
        private GeminiClient $client,
        private GeminiResponseNormalizer $normalizer,
        private GeminiConfig $config,
    ) {}

    public function count(string $text, string $model): TokenResponse
    {
        if (! $this->config->remoteTokenCounting || $text === '') {
            return parent::count($text, $model);
        }

        try {
            return $this->normalizer->tokens(
                $this->client->countTokens($model, ['contents' => [['role' => 'user', 'parts' => [['text' => $text]]]]]),
                $model,
            );
        } catch (AIException) {
            return parent::count($text, $model);
        }
    }
}
