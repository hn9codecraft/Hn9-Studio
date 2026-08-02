<?php

declare(strict_types=1);

namespace App\AI\Providers\ElevenLabs;

use App\AI\Responses\TokenResponse;
use App\AI\Support\AbstractTokenCounter;

/**
 * Character counting for ElevenLabs.
 *
 * ElevenLabs meters **characters**, not tokens: it publishes no tokenizer and
 * has no token concept at all. The shared character-ratio estimate would
 * therefore be actively wrong here, so it is replaced by an exact count of the
 * input text — the same quantity the vendor bills. Being exact rather than
 * estimated, it is authoritative before the call as well as after it.
 *
 * The provider's `countTokens()` deliberately does not use this: that method
 * promises *tokens*, which this vendor does not have, so it reports the
 * capability as unsupported rather than passing off characters as tokens.
 */
final readonly class ElevenLabsTokenCounter extends AbstractTokenCounter
{
    /**
     * The exact number of characters the vendor will bill for this text.
     */
    public function characters(string $text): int
    {
        return mb_strlen($text);
    }

    public function count(string $text, string $model): TokenResponse
    {
        return new TokenResponse($this->characters($text), $model);
    }
}
