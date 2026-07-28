<?php

declare(strict_types=1);

namespace App\AI\DTOs;

use App\AI\Support\Capability;

/**
 * Immutable description of what an AI provider can do. Declared once per
 * provider at registration time and consulted by the manager/registry to route
 * requests — it performs no I/O and calls no API.
 */
final readonly class ProviderCapabilityDTO
{
    /**
     * @param  list<string>  $models
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $version,
        public bool $text = false,
        public bool $image = false,
        public bool $video = false,
        public bool $voice = false,
        public bool $streaming = false,
        public bool $functionCalling = false,
        public array $models = [],
        public ?int $maxInputTokens = null,
        public ?int $maxOutputTokens = null,
    ) {}

    /**
     * Whether the provider declares support for the given capability.
     */
    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::Text => $this->text,
            Capability::Image => $this->image,
            Capability::Video => $this->video,
            Capability::Voice => $this->voice,
            Capability::Streaming => $this->streaming,
            Capability::FunctionCalling => $this->functionCalling,
        };
    }

    /**
     * The capabilities this provider declares.
     *
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return array_values(array_filter(
            Capability::cases(),
            fn (Capability $capability): bool => $this->supports($capability),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'version' => $this->version,
            'capabilities' => array_map(
                static fn (Capability $capability): string => $capability->value,
                $this->capabilities(),
            ),
            'models' => $this->models,
            'max_input_tokens' => $this->maxInputTokens,
            'max_output_tokens' => $this->maxOutputTokens,
        ];
    }
}
