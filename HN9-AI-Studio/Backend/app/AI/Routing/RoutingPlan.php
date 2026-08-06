<?php

declare(strict_types=1);

namespace App\AI\Routing;

/**
 * The ordered providers a dispatch may try, and why the others were dropped.
 *
 * A plan is data: it holds no provider instance and triggers nothing. The
 * dispatcher walks it, but so can a diagnostic endpoint that wants to explain a
 * routing decision without executing it.
 */
final readonly class RoutingPlan
{
    /**
     * @param  list<ProviderCandidate>  $candidates  Best first.
     * @param  array<string, string>  $rejected  Provider key => why it was dropped.
     */
    public function __construct(
        public array $candidates,
        public RoutingContext $context,
        public array $rejected = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->candidates === [];
    }

    public function primary(): ?ProviderCandidate
    {
        return $this->candidates[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn (ProviderCandidate $candidate): string => $candidate->key, $this->candidates);
    }

    /**
     * The plan truncated to the providers a single request may actually try.
     */
    public function limitTo(int $providers): self
    {
        return new self(array_slice($this->candidates, 0, max(1, $providers)), $this->context, $this->rejected);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'capability' => $this->context->capability->value,
            'strategy' => $this->context->strategy,
            'candidates' => array_map(
                static fn (ProviderCandidate $candidate): array => $candidate->toArray(),
                $this->candidates,
            ),
            'rejected' => $this->rejected,
        ];
    }
}
