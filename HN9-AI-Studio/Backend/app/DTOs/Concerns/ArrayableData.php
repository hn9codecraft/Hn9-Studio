<?php

declare(strict_types=1);

namespace App\DTOs\Concerns;

/**
 * Shared behaviour for immutable data transfer objects. Provides a default
 * array projection by reflecting over the DTO's public (readonly) properties
 * and dropping nulls, so DTOs stay pure data with no per-object boilerplate.
 *
 * DTOs carry data only — they contain no business logic and perform no I/O.
 */
trait ArrayableData
{
    /**
     * Project the DTO to an array, omitting null values so it can be handed
     * straight to a mass-assignable model without overwriting defaults.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        /** @var array<string, mixed> $vars */
        $vars = get_object_vars($this);

        return array_filter($vars, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Project the DTO to an array including null values.
     *
     * @return array<string, mixed>
     */
    public function toFullArray(): array
    {
        return get_object_vars($this);
    }
}
