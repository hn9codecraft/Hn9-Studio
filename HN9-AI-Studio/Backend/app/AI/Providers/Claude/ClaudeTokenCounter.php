<?php

declare(strict_types=1);

namespace App\AI\Providers\Claude;

use App\AI\Support\AbstractTokenCounter;

/**
 * Preflight estimate for Claude requests using the shared character-ratio
 * heuristic; Anthropic's reported usage is authoritative after execution.
 */
final readonly class ClaudeTokenCounter extends AbstractTokenCounter {}
