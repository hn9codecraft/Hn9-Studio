<?php

declare(strict_types=1);

namespace App\Story\Support;

/**
 * Preview hash. Includes the M13 estimate amount so a price change invalidates the preview.
 */
final class StoryPromptHash
{
    /**
     * @param  list<string>  $assetIds
     */
    public static function make(
        ?string $provider,
        ?string $model,
        string $capability,
        int $duration,
        ?string $aspect,
        string $final,
        array $assetIds,
        ?StoryCostEstimate $estimate,
    ): string {
        $price = $estimate !== null && $estimate->available && $estimate->amountUsd !== null
            ? number_format($estimate->amountUsd, 4, '.', '')
            : 'unavailable';

        $payload = [
            'provider' => $provider ?? '',
            'model' => $model ?? '',
            'capability' => $capability,
            'duration' => $duration,
            'aspect' => $aspect ?? '',
            'final' => $final,
            'inputs' => array_values($assetIds),
            'price' => $price,
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
