<?php

declare(strict_types=1);

namespace App\AI\Requests;

use App\AI\Contracts\ProviderRequestInterface;
use App\AI\Support\Modality;

/**
 * Immutable text-generation request. Describes intent only — no provider is
 * called by constructing this object.
 */
final readonly class TextRequest implements ProviderRequestInterface
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  list<string>  $stop
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $prompt = '',
        public ?string $model = null,
        public array $messages = [],
        public ?int $maxTokens = null,
        public ?float $temperature = null,
        public ?float $topP = null,
        public bool $stream = false,
        public array $tools = [],
        public ?string $system = null,
        public array $stop = [],
        public array $options = [],
    ) {}

    public function modality(): Modality
    {
        return Modality::Text;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function toArray(): array
    {
        return [
            'modality' => $this->modality()->value,
            'model' => $this->model,
            'prompt' => $this->prompt,
            'messages' => $this->messages,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'stream' => $this->stream,
            'tools' => $this->tools,
            'system' => $this->system,
            'stop' => $this->stop,
            'options' => $this->options,
        ];
    }
}
