<?php

declare(strict_types=1);

namespace App\AI\DTOs;

/**
 * Immutable resolved configuration for a provider, assembled by the config
 * resolver from config/ai.php. Secret values are never hard-coded here; they
 * are supplied at runtime by later sprints.
 */
final readonly class ProviderConfigDTO
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $key,
        public ?string $baseUrl = null,
        public ?string $defaultModel = null,
        public int $timeout = 30,
        public int $maxRetries = 2,
        public array $options = [],
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(string $key, array $config): self
    {
        return new self(
            key: $key,
            baseUrl: isset($config['base_url']) ? (string) $config['base_url'] : null,
            defaultModel: isset($config['default_model']) ? (string) $config['default_model'] : null,
            timeout: (int) ($config['timeout'] ?? 30),
            maxRetries: (int) ($config['max_retries'] ?? 2),
            options: (array) ($config['options'] ?? []),
        );
    }

    /**
     * Read a single option value with a fallback.
     */
    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'base_url' => $this->baseUrl,
            'default_model' => $this->defaultModel,
            'timeout' => $this->timeout,
            'max_retries' => $this->maxRetries,
            'options' => $this->options,
        ];
    }
}
