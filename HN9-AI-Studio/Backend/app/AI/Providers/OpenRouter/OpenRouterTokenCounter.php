<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenRouter;

use App\AI\Support\AbstractTokenCounter;

/**
 * Conservative local estimate for preflight accounting.
 *
 * OpenRouter publishes no tokenizer endpoint, and the tokenizer that ultimately
 * applies depends on which upstream vendor serves the request, so no exact
 * preflight count exists to obtain. The shared character-ratio estimate is used
 * unchanged; the vendor's own `usage` block — surfaced by
 * {@see OpenRouterResponseNormalizer::tokens()} — remains authoritative once a
 * call has executed.
 */
final readonly class OpenRouterTokenCounter extends AbstractTokenCounter {}
