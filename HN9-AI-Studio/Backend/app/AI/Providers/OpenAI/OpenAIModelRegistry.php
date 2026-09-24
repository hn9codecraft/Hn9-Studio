<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenAI;

use App\AI\Support\AbstractModelRegistry;

final readonly class OpenAIModelRegistry extends AbstractModelRegistry
{
    public function __construct(private OpenAIConfig $config)
    {
        parent::__construct('openai', $config->models, $config->defaultModel);
    }

    /**
     * Every model this provider exposes, across modalities.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return array_values(array_unique([
            ...$this->config->models,
            ...$this->config->imageModels,
        ]));
    }

    /**
     * Resolve an image model against the configured image allow-list only.
     */
    public function resolveImage(?string $model): string
    {
        return $this->resolveFrom($model, $this->config->imageModels, $this->config->imageDefaultModel);
    }
}
