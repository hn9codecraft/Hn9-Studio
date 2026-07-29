<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenAI;

use App\AI\Support\AbstractTokenCounter;

/**
 * Conservative local estimate for preflight accounting; OpenAI remains
 * authoritative after execution. OpenAI exposes no token-counting endpoint, so
 * the shared character-ratio estimate is used unchanged.
 */
final readonly class OpenAITokenCounter extends AbstractTokenCounter {}
