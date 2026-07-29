<?php

declare(strict_types=1);

namespace App\AI\Providers\Claude;

use App\AI\Support\AbstractModelRegistry;

final readonly class ClaudeModelRegistry extends AbstractModelRegistry
{
    public function __construct(ClaudeConfig $config)
    {
        parent::__construct('claude', $config->models, $config->defaultModel);
    }
}
