<?php

declare(strict_types=1);

namespace App\AI\Routing;

use App\AI\Contracts\RoutingStrategyInterface;

/**
 * The strategies available to the router, keyed by their configuration name.
 *
 * This is the Open/Closed seam for selection policy: a new strategy is adopted
 * by registering an implementation and naming it in configuration — the router
 * gains no branch and needs no edit. An unknown or misspelled key falls back to
 * the registered default rather than failing a request.
 */
final class RoutingStrategyRegistry
{
    /**
     * @var array<string, RoutingStrategyInterface>
     */
    private array $strategies = [];

    private ?string $default = null;

    public function register(RoutingStrategyInterface $strategy, bool $default = false): void
    {
        $this->strategies[$strategy->key()] = $strategy;

        if ($default || $this->default === null) {
            $this->default = $strategy->key();
        }
    }

    public function has(string $key): bool
    {
        return isset($this->strategies[$key]);
    }

    /**
     * The strategy for a key, or the default when the key is unknown.
     */
    public function get(?string $key): RoutingStrategyInterface
    {
        if ($key !== null && isset($this->strategies[$key])) {
            return $this->strategies[$key];
        }

        return $this->strategies[$this->default] ?? throw new \LogicException(
            'No routing strategy has been registered.',
        );
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->strategies);
    }
}
