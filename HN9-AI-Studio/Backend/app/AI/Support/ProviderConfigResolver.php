<?php

declare(strict_types=1);

namespace App\AI\Support;

use App\AI\DTOs\ProviderConfigDTO;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Resolves a provider's {@see ProviderConfigDTO} from `config/ai.php`. Keeping
 * resolution here (rather than reading config() throughout the layer) means the
 * configuration source can change without touching the factory or manager.
 *
 * This reads static configuration only — no secrets are hard-coded and no
 * network call is made.
 */
final readonly class ProviderConfigResolver
{
    public function __construct(private Config $config) {}

    public function resolve(string $key): ProviderConfigDTO
    {
        /** @var array<string, mixed> $providerConfig */
        $providerConfig = $this->config->get("ai.providers.{$key}", []);

        return ProviderConfigDTO::fromArray($key, $providerConfig);
    }

    /**
     * The configured default provider key, if any.
     */
    public function defaultKey(): ?string
    {
        $default = $this->config->get('ai.default');

        return is_string($default) && $default !== '' ? $default : null;
    }
}
