<?php

declare(strict_types=1);

namespace App\AI\Health;

use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\DTOs\ProviderCapabilityDTO;
use App\AI\Registry\ProviderRegistration;
use App\AI\Support\Capability;

/**
 * Read model over the registry that reports which providers exist and what they
 * can do. Pure introspection of registered metadata — it resolves no provider
 * and calls no external API.
 */
final readonly class CapabilityReport
{
    public function __construct(
        private ProviderRegistryInterface $registry,
    ) {}

    /**
     * Declared capabilities of every enabled provider, keyed by provider key.
     *
     * @return array<string, ProviderCapabilityDTO>
     */
    public function providers(): array
    {
        return array_map(
            static fn (ProviderRegistration $registration): ProviderCapabilityDTO => $registration->capabilities(),
            $this->registry->enabled(),
        );
    }

    /**
     * A capability => provider-keys matrix across enabled providers.
     *
     * @return array<string, list<string>>
     */
    public function matrix(): array
    {
        $matrix = [];

        foreach (Capability::cases() as $capability) {
            $matrix[$capability->value] = $this->registry->forCapability($capability);
        }

        return $matrix;
    }

    /**
     * The full report as a serialisable array.
     *
     * @return array{providers: array<string, array<string, mixed>>, matrix: array<string, list<string>>}
     */
    public function toArray(): array
    {
        return [
            'providers' => array_map(
                static fn (ProviderCapabilityDTO $dto): array => $dto->toArray(),
                $this->providers(),
            ),
            'matrix' => $this->matrix(),
        ];
    }
}
