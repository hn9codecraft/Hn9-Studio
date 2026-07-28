<?php

declare(strict_types=1);

namespace App\AI\Providers;

use App\AI\Contracts\AIProviderInterface;
use App\AI\DTOs\ProviderConfigDTO;
use App\AI\DTOs\ProviderHealthDTO;
use App\AI\DTOs\ProviderRequestDTO;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\AI\Requests\ImageRequest;
use App\AI\Requests\TextRequest;
use App\AI\Requests\VideoRequest;
use App\AI\Requests\VoiceRequest;
use App\AI\Responses\ImageResponse;
use App\AI\Responses\TextResponse;
use App\AI\Responses\TokenResponse;
use App\AI\Responses\VideoResponse;
use App\AI\Responses\VoiceResponse;
use App\AI\Support\Capability;

/**
 * Open/Closed foundation for concrete AI providers. Every generative method
 * defaults to declaring the capability unsupported, so a real provider (a later
 * sprint) overrides only the modalities it actually serves.
 *
 * This is scaffolding, NOT a provider: it is abstract, talks to no backend,
 * generates nothing and fabricates no responses. `countTokens()` uses a
 * transparent local heuristic that providers with a real tokenizer override.
 */
abstract class AbstractProvider implements AIProviderInterface
{
    public function __construct(
        protected readonly ProviderConfigDTO $config,
    ) {}

    abstract public function providerName(): string;

    abstract public function providerVersion(): string;

    public function generateText(TextRequest $request): TextResponse
    {
        throw UnsupportedCapabilityException::make($this->providerName(), Capability::Text);
    }

    public function generateImage(ImageRequest $request): ImageResponse
    {
        throw UnsupportedCapabilityException::make($this->providerName(), Capability::Image);
    }

    public function generateVideo(VideoRequest $request): VideoResponse
    {
        throw UnsupportedCapabilityException::make($this->providerName(), Capability::Video);
    }

    public function generateVoice(VoiceRequest $request): VoiceResponse
    {
        throw UnsupportedCapabilityException::make($this->providerName(), Capability::Voice);
    }

    public function estimateCost(ProviderRequestDTO $request): float
    {
        return 0.0;
    }

    /**
     * Local, provider-agnostic token estimate (~4 characters per token).
     * Providers with a real tokenizer must override this.
     */
    public function countTokens(string $text, ?string $model = null): TokenResponse
    {
        $count = $text === '' ? 0 : (int) ceil(mb_strlen($text) / 4);

        return new TokenResponse($count, $model);
    }

    public function supportsStreaming(): bool
    {
        return false;
    }

    public function supportsFunctionCalling(): bool
    {
        return false;
    }

    public function supportedModels(): array
    {
        return [];
    }

    public function healthCheck(): ProviderHealthDTO
    {
        return ProviderHealthDTO::unknown($this->providerName());
    }
}
