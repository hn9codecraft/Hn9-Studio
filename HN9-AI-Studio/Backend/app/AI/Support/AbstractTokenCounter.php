<?php

declare(strict_types=1);

namespace App\AI\Support;

use App\AI\Responses\TokenResponse;

/**
 * Shared local token estimate for provider adapters (~4 characters per token).
 *
 * The estimate is transparent and deliberately conservative: it is a preflight
 * figure for cost accounting, and the vendor remains authoritative once a call
 * has executed. Providers exposing a real tokenizer override {@see self::count()}.
 */
abstract readonly class AbstractTokenCounter
{
    protected const CHARACTERS_PER_TOKEN = 4;

    public function count(string $text, string $model): TokenResponse
    {
        return new TokenResponse($this->estimate($text), $model);
    }

    protected function estimate(string $text): int
    {
        return $text === '' ? 0 : (int) ceil(mb_strlen($text) / self::CHARACTERS_PER_TOKEN);
    }
}
