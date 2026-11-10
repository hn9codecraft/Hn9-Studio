<?php

declare(strict_types=1);

namespace App\Story\Support;

/**
 * A price estimate from the configured rate table. Null rates stay unavailable.
 */
final readonly class StoryCostEstimate
{
    public function __construct(
        public string $kind,
        public ?string $providerKey,
        public ?string $modelKey,
        public string $units,
        public float $quantity,
        public ?float $rateUsd,
        public ?float $amountUsd,
        public ?float $amountUsdHigh,
        public ?float $amountDisplay,
        public string $displayCurrency,
        public bool $available,
        public bool $providerConnected,
        public string $source = 'configured_pricing',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'provider_key' => $this->providerKey,
            'model_key' => $this->modelKey,
            'units' => $this->units,
            'quantity' => $this->quantity,
            'rate_usd' => $this->rateUsd,
            'amount_usd' => $this->amountUsd,
            'amount_usd_high' => $this->amountUsdHigh,
            'amount_display' => $this->amountDisplay,
            'display_currency' => $this->displayCurrency,
            'available' => $this->available,
            'provider_connected' => $this->providerConnected,
            'source' => $this->source,
            'estimate_available' => $this->available,
        ];
    }

    /**
     * Stored on the job only. Never copied into the reported-cost ledger.
     *
     * @return array<string, mixed>
     */
    public function forJob(): array
    {
        return [
            'amount_usd' => $this->amountUsd,
            'amount_usd_high' => $this->amountUsdHigh,
            'rate_usd' => $this->rateUsd,
            'quantity' => $this->quantity,
            'units' => $this->units,
            'provider_key' => $this->providerKey,
            'model_key' => $this->modelKey,
            'source' => $this->source,
            'available' => $this->available,
        ];
    }
}
