<?php

declare(strict_types=1);

namespace App\AI\Providers\Xai;

use App\AI\Support\AbstractModelRegistry;

final readonly class XaiModelRegistry extends AbstractModelRegistry
{
    public function __construct(private XaiConfig $config)
    {
        parent::__construct(XaiConfig::KEY, $config->models, $config->defaultModel);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_values(array_unique([...$this->config->models, ...$this->config->imageModels]));
    }

    public function resolveImage(?string $model): string
    {
        return $this->resolveFrom($model, $this->config->imageModels, $this->config->imageDefaultModel);
    }
}
