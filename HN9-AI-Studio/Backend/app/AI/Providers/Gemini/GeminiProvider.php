<?php

declare(strict_types=1);

namespace App\AI\Providers\Gemini;

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
use App\AI\Support\HealthStatus;
use App\AI\Support\Modality;
use Throwable;

/**
 * Google Gemini adapter over the Generative Language API.
 *
 * Text and image output both travel through `generateContent`; image models are
 * configured separately and requested with the configured response modalities.
 * Video (Veo) and speech generation are out of this adapter's scope and raise
 * the shared unsupported-capability exception rather than being approximated.
 */
final class GeminiProvider extends AbstractProvider
{
    public const VERSION = '1.0.0';

    private const KEY = 'gemini';

    public function __construct(
        private readonly GeminiClient $client,
        private readonly GeminiModelRegistry $models,
        private readonly GeminiUsageCalculator $usageCalculator,
        private readonly GeminiResponseNormalizer $normalizer,
        private readonly GeminiTokenCounter $tokenCounter,
        private readonly GeminiConfig $geminiConfig,
    ) {
        parent::__construct(new ProviderConfigDTO(
            self::KEY,
            $geminiConfig->endpoint(),
            $geminiConfig->defaultModel,
            $geminiConfig->timeout,
            $geminiConfig->maxRetries,
        ));
    }

    public function providerName(): string
    {
        return self::KEY;
    }

    public function providerVersion(): string
    {
        return self::VERSION;
    }

    public function generateText(TextRequest $request): TextResponse
    {
        $model = $this->models->resolve($request->model);
        $startedAt = hrtime(true);
        $response = $this->client->generateContent($model, $this->textPayload($request));

        return $this->normalizer->text($response, $model, $this->elapsedMilliseconds($startedAt));
    }

    public function generateImage(ImageRequest $request): ImageResponse
    {
        $model = $this->models->resolveImage($request->model);
        $startedAt = hrtime(true);
        $response = $this->client->generateContent($model, $this->imagePayload($request));

        return $this->normalizer->image($response, $model, $this->elapsedMilliseconds($startedAt));
    }

    public function generateVideo(VideoRequest $request): VideoResponse
    {
        throw UnsupportedCapabilityException::make(self::KEY, Capability::Video);
    }

    public function generateVoice(VoiceRequest $request): VoiceResponse
    {
        throw UnsupportedCapabilityException::make(self::KEY, Capability::Voice);
    }

    public function estimateCost(ProviderRequestDTO $request): float
    {
        $model = $request->modality === Modality::Image
            ? $this->models->resolveImage($request->model)
            : $this->models->resolve($request->model);

        $prompt = (string) ($request->parameters['prompt'] ?? '');
        $input = $this->tokenCounter->count($prompt, $model)->count;
        $output = (int) ($request->parameters['max_tokens'] ?? 0);

        return $this->usageCalculator->fromUsageMetadata(
            ['promptTokenCount' => $input, 'candidatesTokenCount' => $output],
            $model,
        )->cost;
    }

    public function countTokens(string $text, ?string $model = null): TokenResponse
    {
        return $this->tokenCounter->count($text, $this->models->resolve($model));
    }

    public function supportedModels(): array
    {
        return $this->models->all();
    }

    /**
     * Gemini streams through a dedicated `:streamGenerateContent` route; the
     * shared provider contract is synchronous, so this reports the configured
     * capability without altering the request payload.
     */
    public function supportsStreaming(): bool
    {
        return $this->geminiConfig->supportsStreaming;
    }

    public function supportsFunctionCalling(): bool
    {
        return $this->geminiConfig->supportsFunctionCalling;
    }

    /**
     * Probes connectivity, authentication and the configured default model with
     * a read-only model-metadata request — no generation is billed.
     */
    public function healthCheck(): ProviderHealthDTO
    {
        $checkedAt = now()->toIso8601String();

        try {
            $model = $this->models->resolve(null);
            $startedAt = hrtime(true);
            $metadata = $this->client->model($model);

            return new ProviderHealthDTO(
                key: self::KEY,
                status: HealthStatus::Healthy,
                latencyMs: $this->elapsedMilliseconds($startedAt),
                checkedAt: $checkedAt,
                details: [
                    'api_version' => $this->geminiConfig->version,
                    'default_model' => $model,
                    'model_verified' => isset($metadata['name']),
                    'text_models' => $this->models->textModels(),
                    'image_models' => $this->models->imageModels(),
                ],
            );
        } catch (Throwable $exception) {
            return ProviderHealthDTO::unavailable(self::KEY, $exception->getMessage(), $checkedAt);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function textPayload(TextRequest $request): array
    {
        $generationConfig = array_filter([
            'maxOutputTokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'topP' => $request->topP,
            'stopSequences' => $request->stop !== [] ? $request->stop : null,
        ], static fn (mixed $value): bool => $value !== null);

        $payload = array_filter([
            'contents' => $this->contents($request),
            'systemInstruction' => $this->systemInstruction($request),
            'generationConfig' => $generationConfig !== [] ? $generationConfig : null,
            'tools' => $request->tools !== [] ? [['functionDeclarations' => $request->tools]] : null,
        ], static fn (mixed $value): bool => $value !== null);

        return [...$payload, ...$request->options];
    }

    /**
     * `generateContent` exposes no negative-prompt, size, quality or style
     * parameter, so those request fields are not sent; anything vendor-specific
     * can still be supplied through the request's `options`.
     *
     * @return array<string, mixed>
     */
    private function imagePayload(ImageRequest $request): array
    {
        $generationConfig = array_filter([
            'responseModalities' => $this->geminiConfig->imageResponseModalities !== []
                ? $this->geminiConfig->imageResponseModalities
                : null,
            'candidateCount' => $request->count > 1 ? $request->count : null,
            'seed' => $request->seed,
        ], static fn (mixed $value): bool => $value !== null);

        $payload = array_filter([
            'contents' => [$this->userContent($request->prompt)],
            'generationConfig' => $generationConfig !== [] ? $generationConfig : null,
        ], static fn (mixed $value): bool => $value !== null);

        return [...$payload, ...$request->options];
    }

    /**
     * Gemini uses `user`/`model` roles; system turns are hoisted into
     * `systemInstruction` because the API rejects them inside `contents`.
     *
     * @return list<array<string, mixed>>
     */
    private function contents(TextRequest $request): array
    {
        if ($request->messages === []) {
            return [$this->userContent($request->prompt)];
        }

        $contents = [];

        foreach ($request->messages as $message) {
            if ($message['role'] === 'system') {
                continue;
            }

            $contents[] = [
                'role' => in_array($message['role'], ['assistant', 'model'], true) ? 'model' : 'user',
                'parts' => [['text' => $message['content']]],
            ];
        }

        return $contents;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function systemInstruction(TextRequest $request): ?array
    {
        $instructions = $request->system !== null && $request->system !== '' ? [$request->system] : [];

        foreach ($request->messages as $message) {
            if ($message['role'] === 'system' && $message['content'] !== '') {
                $instructions[] = $message['content'];
            }
        }

        if ($instructions === []) {
            return null;
        }

        return ['parts' => array_map(static fn (string $text): array => ['text' => $text], $instructions)];
    }

    /**
     * @return array<string, mixed>
     */
    private function userContent(string $text): array
    {
        return ['role' => 'user', 'parts' => [['text' => $text]]];
    }
}
