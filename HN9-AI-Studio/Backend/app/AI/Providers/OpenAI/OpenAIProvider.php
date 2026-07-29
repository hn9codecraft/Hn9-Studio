<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenAI;

use App\AI\DTOs\ProviderConfigDTO;
use App\AI\DTOs\ProviderHealthDTO;
use App\AI\DTOs\ProviderRequestDTO;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\AI\Providers\AbstractProvider;
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
use Throwable;

final class OpenAIProvider extends AbstractProvider
{
    public const VERSION = '1.0.0';

    public function __construct(
        private readonly OpenAIClient $client,
        private readonly OpenAIModelRegistry $models,
        private readonly OpenAIUsageCalculator $usageCalculator,
        private readonly OpenAIResponseNormalizer $normalizer,
        private readonly OpenAITokenCounter $tokenCounter,
        private readonly OpenAIConfig $openAIConfig,
    ) {
        parent::__construct(new ProviderConfigDTO('openai', $openAIConfig->baseUrl, $openAIConfig->defaultModel, $openAIConfig->timeout, $openAIConfig->maxRetries));
    }

    public function providerName(): string
    {
        return 'openai';
    }

    public function providerVersion(): string
    {
        return self::VERSION;
    }

    public function generateText(TextRequest $request): TextResponse
    {
        $model = $this->models->resolve($request->model);
        $startedAt = hrtime(true);
        $response = $this->client->post('responses', $this->textPayload($request, $model));

        return $this->normalizer->text($response, $model, $this->elapsedMilliseconds($startedAt));
    }

    public function generateImage(ImageRequest $request): ImageResponse
    {
        $model = $this->models->resolve($request->model);
        $payload = array_filter([
            'model' => $model, 'prompt' => $request->prompt, 'n' => $request->count,
            'size' => $request->size, 'quality' => $request->quality, 'style' => $request->style,
            'response_format' => $request->format,
        ], static fn (mixed $value): bool => $value !== null);
        $startedAt = hrtime(true);
        $response = $this->client->post('images/generations', $payload);

        return $this->normalizer->image($response, $model, $this->elapsedMilliseconds($startedAt));
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
        $model = $this->models->resolve($request->model);
        $prompt = (string) ($request->parameters['prompt'] ?? '');
        $input = $this->tokenCounter->count($prompt, $model)->count;
        $output = (int) ($request->parameters['max_tokens'] ?? 0);
        $usage = $this->usageCalculator->fromUsage(['input_tokens' => $input, 'output_tokens' => $output], $model);

        return $usage->cost;
    }

    public function countTokens(string $text, ?string $model = null): TokenResponse
    {
        return $this->tokenCounter->count($text, $this->models->resolve($model));
    }

    public function supportsStreaming(): bool
    {
        return $this->openAIConfig->supportsStreaming;
    }

    public function supportsFunctionCalling(): bool
    {
        return $this->openAIConfig->supportsFunctionCalling;
    }

    public function supportedModels(): array
    {
        return $this->models->all();
    }

    public function healthCheck(): ProviderHealthDTO
    {
        try {
            $model = $this->models->resolve(null);
            $startedAt = hrtime(true);
            $this->client->get('models/'.rawurlencode($model));

            return ProviderHealthDTO::healthy('openai', $this->elapsedMilliseconds($startedAt), now()->toIso8601String());
        } catch (Throwable $exception) {
            return ProviderHealthDTO::unavailable('openai', $exception->getMessage(), now()->toIso8601String());
        }
    }

    /** @return array<string, mixed> */
    private function textPayload(TextRequest $request, string $model): array
    {
        $input = $request->messages !== [] ? $request->messages : $request->prompt;
        $payload = array_filter([
            'model' => $model, 'input' => $input, 'instructions' => $request->system,
            'max_output_tokens' => $request->maxTokens, 'temperature' => $request->temperature,
            'top_p' => $request->topP, 'stream' => $request->stream, 'tools' => $request->tools ?: null,
        ], static fn (mixed $value): bool => $value !== null);

        return [...$payload, ...$request->options];
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
