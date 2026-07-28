<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\Support\Modality;

/**
 * Contract implemented by every typed provider request object (text, image,
 * video, voice). Lets the manager/factory reason about a request generically
 * without knowing its concrete modality.
 */
interface ProviderRequestInterface
{
    /**
     * The modality this request targets.
     */
    public function modality(): Modality;

    /**
     * The requested model identifier, or null to use the provider default.
     */
    public function model(): ?string;

    /**
     * The request as a normalised array (provider-agnostic).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
