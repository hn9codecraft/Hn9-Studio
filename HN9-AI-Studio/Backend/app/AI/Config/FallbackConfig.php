<?php

declare(strict_types=1);

namespace App\AI\Config;

use App\AI\Exceptions\AIException;
use App\AI\Support\Capability;
use Throwable;

/**
 * The configured fallback behaviour: how many providers one request may try,
 * which failures move it along, and the operator-pinned chain per capability.
 *
 * Chains are supplied entirely by configuration — e.g.
 * `AI_FALLBACK_CHAIN_TEXT="openai,claude,gemini,openrouter"` — so no provider
 * key is named anywhere in the routing or execution code.
 */
final readonly class FallbackConfig
{
    public const MODE_ORDER = 'order';

    public const MODE_RESTRICT = 'restrict';

    /**
     * @param  array<string, list<string>>  $chains  Capability value => ordered provider keys.
     * @param  list<class-string>  $exceptions  Failures that trigger a fallback.
     */
    public function __construct(
        public bool $enabled = true,
        public int $maxProviders = 3,
        public string $mode = self::MODE_ORDER,
        public array $chains = [],
        public array $exceptions = [AIException::class],
    ) {}

    public static function fromReader(ConfigReader $reader): self
    {
        $mode = $reader->string('mode', self::MODE_ORDER);
        $exceptions = $reader->classList('exceptions');

        return new self(
            enabled: $reader->bool('enabled', true),
            maxProviders: max(1, $reader->int('max_providers', 3)),
            mode: in_array($mode, [self::MODE_ORDER, self::MODE_RESTRICT], true) ? $mode : self::MODE_ORDER,
            chains: $reader->listMap('chains'),
            exceptions: $exceptions === [] ? [AIException::class] : $exceptions,
        );
    }

    /**
     * The pinned chain for a capability, or an empty list when none is set.
     *
     * @return list<string>
     */
    public function chainFor(Capability $capability): array
    {
        return $this->chains[$capability->value] ?? [];
    }

    /**
     * Whether the chain constrains the candidate set rather than ordering it.
     */
    public function restricts(): bool
    {
        return $this->mode === self::MODE_RESTRICT;
    }

    /**
     * Whether this failure should hand the request to the next provider. A
     * failure outside the configured list is a caller or programming error and
     * propagates immediately rather than burning the whole chain.
     */
    public function coversFailure(Throwable $failure): bool
    {
        foreach ($this->exceptions as $class) {
            if ($failure instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * The number of providers a request may try, honouring a caller override.
     */
    public function providerBudget(?int $override = null): int
    {
        if (! $this->enabled) {
            return 1;
        }

        return $override === null ? $this->maxProviders : max(1, $override);
    }
}
