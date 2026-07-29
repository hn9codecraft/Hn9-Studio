<?php

declare(strict_types=1);

namespace App\AI\Support;

use App\AI\Exceptions\ProviderNotConfiguredException;

/**
 * Shared model bookkeeping for provider adapters: the configured model list and
 * the resolution of a requested (or default) model against it.
 *
 * Every model identifier originates in configuration — no model is named here,
 * so new vendor models are adopted by configuration alone.
 */
abstract readonly class AbstractModelRegistry
{
    /**
     * @param  list<string>  $models
     */
    public function __construct(
        protected string $providerKey,
        protected array $models,
        protected ?string $defaultModel,
    ) {}

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->models;
    }

    public function resolve(?string $model): string
    {
        return $this->resolveFrom($model, $this->models, $this->defaultModel);
    }

    /**
     * Resolve a model against an explicit allow-list, falling back to a default.
     *
     * @param  list<string>  $allowed
     */
    protected function resolveFrom(?string $model, array $allowed, ?string $default): string
    {
        $resolved = $model ?? $default;

        if ($resolved === null || $resolved === '' || ! in_array($resolved, $allowed, true)) {
            throw ProviderNotConfiguredException::forKey($this->providerKey);
        }

        return $resolved;
    }
}
