<?php

declare(strict_types=1);

namespace Tests\Support;

use App\AI\DTOs\ProviderConfigDTO;
use App\AI\DTOs\ProviderRequestDTO;
use App\AI\Providers\AbstractProvider;
use App\AI\Requests\ImageRequest;
use App\AI\Requests\TextRequest;
use App\AI\Requests\VoiceRequest;
use App\AI\Responses\ImageResponse;
use App\AI\Responses\TextResponse;
use App\AI\Responses\VoiceResponse;
use Throwable;

/**
 * A scripted provider double for the platform suites.
 *
 * It is a test double, not a provider: it contacts nothing, and its "responses"
 * are literals the test supplied. What it does faithfully is *behave* — succeed,
 * fail with a given typed exception, or fail a set number of times and then
 * recover — which is what routing, retry, fallback and circuit breaking are
 * being measured against.
 */
final class RecordingProvider extends AbstractProvider
{
    /**
     * Calls received, so a test can assert how often a provider was reached.
     */
    public int $calls = 0;

    /**
     * @param  list<Throwable|null>  $script  Consumed one entry per call; null is a success.
     * @param  Throwable|null  $then  The outcome once the script runs out.
     */
    public function __construct(
        private readonly string $name,
        private array $script = [],
        private readonly ?Throwable $then = null,
        private readonly float $cost = 0.0,
    ) {
        parent::__construct(new ProviderConfigDTO($name));
    }

    /**
     * Always succeeds.
     */
    public static function succeeding(string $name, float $cost = 0.0): self
    {
        return new self($name, cost: $cost);
    }

    /**
     * Always fails with a fresh copy of the given failure.
     */
    public static function failing(string $name, Throwable $failure, float $cost = 0.0): self
    {
        return new self($name, then: $failure, cost: $cost);
    }

    /**
     * Fails the first `$times` calls, then succeeds.
     */
    public static function recoveringAfter(string $name, int $times, Throwable $failure, float $cost = 0.0): self
    {
        return new self($name, array_fill(0, $times, $failure), cost: $cost);
    }

    public function providerName(): string
    {
        return $this->name;
    }

    public function providerVersion(): string
    {
        return '1.0.0-test';
    }

    public function generateText(TextRequest $request): TextResponse
    {
        $this->record();

        return new TextResponse("{$this->name}:{$request->prompt}", $request->model);
    }

    public function generateImage(ImageRequest $request): ImageResponse
    {
        $this->record();

        return new ImageResponse(["{$this->name}://image"], $request->model);
    }

    public function generateVoice(VoiceRequest $request): VoiceResponse
    {
        $this->record();

        return new VoiceResponse("{$this->name}://audio", $request->model);
    }

    public function estimateCost(ProviderRequestDTO $request): float
    {
        return $this->cost;
    }

    /**
     * Count the call and honour the script.
     */
    private function record(): void
    {
        $this->calls++;

        $outcome = $this->script === [] ? $this->then : array_shift($this->script);

        if ($outcome !== null) {
            throw $outcome;
        }
    }
}
