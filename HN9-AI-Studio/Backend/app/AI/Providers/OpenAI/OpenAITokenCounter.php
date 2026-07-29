<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenAI;

use App\AI\Responses\TokenResponse;

/** Conservative local estimate for preflight accounting; OpenAI remains authoritative after execution. */
final class OpenAITokenCounter
{
    public function count(string $text, string $model): TokenResponse
    {
        return new TokenResponse($text === '' ? 0 : (int) ceil(mb_strlen($text) / 4), $model);
    }
}
