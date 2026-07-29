<?php

declare(strict_types=1);

namespace App\AI\Providers\Claude;

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

final class ClaudeProvider extends AbstractProvider
{
    public const VERSION = '1.0.0';

    public function __construct(private readonly ClaudeClient $client, private readonly ClaudeModelRegistry $models, private readonly ClaudeUsageCalculator $usage, private readonly ClaudeResponseNormalizer $normalizer, private readonly ClaudeTokenCounter $tokens, private readonly ClaudeConfig $claudeConfig)
    {
        parent::__construct(new ProviderConfigDTO('claude', $claudeConfig->baseUrl, $claudeConfig->defaultModel, $claudeConfig->timeout, $claudeConfig->maxRetries));
    }

    public function providerName(): string
    {
        return 'claude';
    }

    public function providerVersion(): string
    {
        return self::VERSION;
    }

    public function generateText(TextRequest $request): TextResponse
    {
        $model = $this->models->resolve($request->model);
        $started = hrtime(true);
        $messages = $request->messages !== [] ? $request->messages : [['role' => 'user', 'content' => $request->prompt]];
        $payload = array_filter(['model' => $model, 'max_tokens' => $request->maxTokens, 'system' => $request->system, 'messages' => $messages, 'temperature' => $request->temperature, 'top_p' => $request->topP, 'stream' => $request->stream, 'tools' => $request->tools ?: null, 'stop_sequences' => $request->stop ?: null], static fn (mixed $value): bool => $value !== null);

        return $this->normalizer->text($this->client->messages([...$payload, ...$request->options]), $model, $this->elapsedMilliseconds($started));
    }

    public function generateImage(ImageRequest $request): ImageResponse
    {
        throw UnsupportedCapabilityException::make('claude', Capability::Image);
    }

    public function generateVideo(VideoRequest $request): VideoResponse
    {
        throw UnsupportedCapabilityException::make('claude', Capability::Video);
    }

    public function generateVoice(VoiceRequest $request): VoiceResponse
    {
        throw UnsupportedCapabilityException::make('claude', Capability::Voice);
    }

    public function estimateCost(ProviderRequestDTO $request): float
    {
        $model = $this->models->resolve($request->model);

        return $this->usage->fromUsage(['input_tokens' => $this->tokens->count((string) ($request->parameters['prompt'] ?? ''), $model)->count, 'output_tokens' => (int) ($request->parameters['max_tokens'] ?? 0)], $model)->cost;
    }

    public function countTokens(string $text, ?string $model = null): TokenResponse
    {
        return $this->tokens->count($text, $this->models->resolve($model));
    }

    public function supportedModels(): array
    {
        return $this->models->all();
    }

    public function supportsStreaming(): bool
    {
        return $this->claudeConfig->supportsStreaming;
    }

    public function supportsFunctionCalling(): bool
    {
        return $this->claudeConfig->supportsFunctionCalling;
    }

    public function healthCheck(): ProviderHealthDTO
    {
        try {
            $model = $this->models->resolve(null);
            $started = hrtime(true);
            $this->client->messages(['model' => $model, 'max_tokens' => 1, 'messages' => [['role' => 'user', 'content' => 'health check']]]);

            return ProviderHealthDTO::healthy('claude', $this->elapsedMilliseconds($started), now()->toIso8601String());
        } catch (Throwable $exception) {
            return ProviderHealthDTO::unavailable('claude', $exception->getMessage(), now()->toIso8601String());
        }
    }
}
