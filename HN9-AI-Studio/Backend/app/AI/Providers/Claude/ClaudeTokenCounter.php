<?php

declare(strict_types=1);

namespace App\AI\Providers\Claude;

use App\AI\Responses\TokenResponse;

final class ClaudeTokenCounter
{
    public function count(string $text, string $model): TokenResponse
    {
        return new TokenResponse($text === '' ? 0 : (int) ceil(mb_strlen($text) / 4), $model);
    }
}
