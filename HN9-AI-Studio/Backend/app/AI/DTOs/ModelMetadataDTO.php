<?php

declare(strict_types=1);

namespace App\AI\DTOs;

use App\AI\Support\Capability;

/**
 * Immutable, provider-independent description of a single model.
 *
 * Where {@see ProviderCapabilityDTO} describes what an adapter as a whole can
 * do, this describes one model within it: the upstream vendor serving it, the
 * modalities it answers, its context window and its per-million-token rates.
 * It is needed by aggregator providers, whose catalogue spans many vendors with
 * materially different limits and prices.
 *
 * Every field originates in configuration or in the model identifier itself —
 * nothing is inferred from a hardcoded model table, and an unconfigured value
 * stays null rather than being guessed.
 */
final readonly class ModelMetadataDTO
{
    /**
     * @param  list<Capability>  $capabilities  Generative modalities the model answers.
     * @param  array<string, float>  $pricing  Per-million-token rates, e.g. `['input' => 3.0, 'output' => 15.0]`.
     */
    public function __construct(
        public string $id,
        public ?string $provider = null,
        public array $capabilities = [],
        public bool $streaming = false,
        public bool $functionCalling = false,
        public ?int $contextWindow = null,
        public ?int $maxOutputTokens = null,
        public array $pricing = [],
    ) {}

    /**
     * Whether the model declares support for the given capability.
     */
    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::Streaming => $this->streaming,
            Capability::FunctionCalling => $this->functionCalling,
            default => in_array($capability, $this->capabilities, true),
        };
    }

    /**
     * Whether configuration supplies rates for this model. Without them the
     * usage calculator prices the model at zero rather than inventing a rate.
     */
    public function isPriced(): bool
    {
        return $this->pricing !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'capabilities' => array_map(
                static fn (Capability $capability): string => $capability->value,
                $this->capabilities,
            ),
            'streaming' => $this->streaming,
            'function_calling' => $this->functionCalling,
            'context_window' => $this->contextWindow,
            'max_output_tokens' => $this->maxOutputTokens,
            'pricing' => $this->pricing === [] ? null : $this->pricing,
        ];
    }
}
