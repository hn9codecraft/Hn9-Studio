<?php

declare(strict_types=1);

namespace App\AI\Responses;

/**
 * Immutable result of a token-counting operation.
 */
final readonly class TokenResponse
{
    public function __construct(
        public int $count,
        public ?string $model = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'model' => $this->model,
        ];
    }
}
