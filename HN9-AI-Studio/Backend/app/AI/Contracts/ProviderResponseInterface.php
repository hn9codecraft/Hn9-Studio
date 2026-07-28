<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\Support\Modality;

/**
 * Contract implemented by every provider response object. Provides a uniform
 * projection so responses of any modality can be serialised consistently.
 */
interface ProviderResponseInterface
{
    /**
     * The modality this response belongs to.
     */
    public function modality(): Modality;

    /**
     * The response as a normalised array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
