<?php

declare(strict_types=1);

namespace App\AI\Providers\Gemini;

use App\AI\Support\AbstractModelRegistry;

/**
 * Gemini model bookkeeping. Text and image-capable models are configured
 * separately because the same `generateContent` route serves both, and only
 * image-output models may be asked for image modalities.
 */
final readonly class GeminiModelRegistry extends AbstractModelRegistry
{
    public function __construct(private GeminiConfig $config)
    {
        parent::__construct('gemini', $config->models, $config->defaultModel);
    }

    /**
     * Every model this provider exposes, across modalities.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return array_values(array_unique([...$this->config->models, ...$this->config->imageModels]));
    }

    /**
     * @return list<string>
     */
    public function textModels(): array
    {
        return $this->config->models;
    }

    /**
     * @return list<string>
     */
    public function imageModels(): array
    {
        return $this->config->imageModels;
    }

    public function resolveImage(?string $model): string
    {
        return $this->resolveFrom($model, $this->config->imageModels, $this->config->imageDefaultModel);
    }
}
