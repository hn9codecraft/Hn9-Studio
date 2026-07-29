<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenAI;

use App\AI\Support\AbstractModelRegistry;

final readonly class OpenAIModelRegistry extends AbstractModelRegistry
{
    public function __construct(OpenAIConfig $config)
    {
        parent::__construct('openai', $config->models, $config->defaultModel);
    }
}
