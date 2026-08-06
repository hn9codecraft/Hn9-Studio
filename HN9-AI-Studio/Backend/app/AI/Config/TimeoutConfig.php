<?php

declare(strict_types=1);

namespace App\AI\Config;

/**
 * The platform's three timeout layers.
 *
 * `connect` and `request` are transport defaults every provider client applies
 * unless its own block overrides them. `totalMs` is the end-to-end budget for a
 * single dispatch — every retry and every fallback included — after which the
 * platform stops attempting and fails gracefully rather than compounding delay.
 */
final readonly class TimeoutConfig
{
    public function __construct(
        public int $connect = 10,
        public int $request = 30,
        public int $totalMs = 120_000,
    ) {}

    public static function fromReader(ConfigReader $reader): self
    {
        return new self(
            connect: max(0, $reader->int('connect', 10)),
            request: max(0, $reader->int('request', 30)),
            totalMs: max(0, $reader->int('total_ms', 120_000)),
        );
    }

    /**
     * The dispatch budget in milliseconds, honouring a caller override. Zero
     * means "no overall deadline".
     */
    public function budgetMs(?int $override = null): ?int
    {
        $budget = $override ?? $this->totalMs;

        return $budget > 0 ? $budget : null;
    }

    /**
     * The request timeout a provider should use: its own when configured,
     * otherwise the platform default.
     */
    public function requestTimeoutFor(int $providerTimeout): int
    {
        return $providerTimeout > 0 ? $providerTimeout : $this->request;
    }
}
