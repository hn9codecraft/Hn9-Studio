<?php

declare(strict_types=1);

namespace App\AI\Contracts;

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

/**
 * The contract every concrete AI provider must fulfil.
 *
 * This interface defines behaviour ONLY — Sprint 5.3.1 ships no implementation.
 * Concrete providers (later sprints) implement this to talk to a real backend;
 * unsupported modalities throw {@see UnsupportedCapabilityException}.
 */
interface AIProviderInterface extends ProviderHealthInterface
{
    public function generateText(TextRequest $request): TextResponse;

    public function generateImage(ImageRequest $request): ImageResponse;

    public function generateVideo(VideoRequest $request): VideoResponse;

    public function generateVoice(VoiceRequest $request): VoiceResponse;

    /**
     * Estimate the cost (in the provider's billing currency) of a request
     * without executing it.
     */
    public function estimateCost(ProviderRequestDTO $request): float;

    /**
     * Count the tokens a piece of text would consume for the given model.
     */
    public function countTokens(string $text, ?string $model = null): TokenResponse;

    public function supportsStreaming(): bool;

    public function supportsFunctionCalling(): bool;

    /**
     * The model identifiers this provider exposes.
     *
     * @return list<string>
     */
    public function supportedModels(): array;

    /**
     * The provider's unique key/name (e.g. "openai").
     */
    public function providerName(): string;

    /**
     * The provider adapter's version.
     */
    public function providerVersion(): string;
}
