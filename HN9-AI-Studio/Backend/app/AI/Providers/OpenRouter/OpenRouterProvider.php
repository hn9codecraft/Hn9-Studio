<?php

declare(strict_types=1);

namespace App\AI\Providers\OpenRouter;

use App\AI\DTOs\ModelMetadataDTO;
use App\AI\DTOs\ProviderConfigDTO;
use App\AI\DTOs\ProviderHealthDTO;
use App\AI\DTOs\ProviderRequestDTO;
use App\AI\Exceptions\AIException;
use App\AI\Providers\AbstractProvider;
use App\AI\Requests\TextRequest;
use App\AI\Responses\TextResponse;
use App\AI\Responses\TokenResponse;
use App\AI\Support\HealthStatus;
use Throwable;

/**
 * OpenRouter adapter — a single credential and endpoint fronting many upstream
 * vendors (OpenAI, Anthropic, Google, DeepSeek, Llama, Mistral, Qwen and
 * whatever the catalogue gains next), reached through one OpenAI-compatible
 * `chat/completions` route.
 *
 * Which models are reachable is entirely a matter of configuration: this class
 * names none, and adopting a newly published model requires no code change.
 * Router-specific request features (endpoint ordering, model fallbacks,
 * transforms) are passed through untouched via the request's `options`, so the
 * shared request contract does not have to grow vendor fields.
 *
 * Only text generation is implemented. Image, video and voice inherit
 * {@see AbstractProvider}'s unsupported-capability behaviour deliberately: this
 * adapter routes the chat-completions modality, and no image, video or speech
 * route is declared here rather than approximating one.
 */
final class OpenRouterProvider extends AbstractProvider
{
    public const VERSION = '1.0.0';

    private const KEY = OpenRouterConfig::KEY;

    public function __construct(
        private readonly OpenRouterClient $client,
        private readonly OpenRouterModelRegistry $models,
        private readonly OpenRouterUsageCalculator $usageCalculator,
        private readonly OpenRouterResponseNormalizer $normalizer,
        private readonly OpenRouterTokenCounter $tokenCounter,
        private readonly OpenRouterConfig $openRouterConfig,
    ) {
        parent::__construct(new ProviderConfigDTO(
            self::KEY,
            $openRouterConfig->baseUrl,
            $openRouterConfig->defaultModel,
            $openRouterConfig->timeout,
            $openRouterConfig->maxRetries,
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
        $response = $this->client->chatCompletions($this->textPayload($request, $model));

        return $this->normalizer->text($response, $model, $this->elapsedMilliseconds($startedAt));
    }

    public function estimateCost(ProviderRequestDTO $request): float
    {
        $model = $this->models->resolve($request->model);
        $prompt = $this->tokenCounter->count((string) ($request->parameters['prompt'] ?? ''), $model)->count;

        return $this->usageCalculator->fromUsage(
            [
                'prompt_tokens' => $prompt,
                'completion_tokens' => (int) ($request->parameters['max_tokens'] ?? 0),
            ],
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
     * Metadata for every configured model, keyed by identifier.
     *
     * @return array<string, ModelMetadataDTO>
     */
    public function modelMetadata(): array
    {
        return $this->models->metadata();
    }

    /**
     * Metadata for one model, defaulting to the configured default model. The
     * identifier is resolved first, so an unconfigured model is rejected here
     * exactly as it would be by a generation request.
     */
    public function modelMetadataFor(?string $model = null): ModelMetadataDTO
    {
        return $this->models->metadataFor($this->models->resolve($model));
    }

    /**
     * OpenRouter streams over server-sent events on the same route; the shared
     * provider contract is synchronous, so this reports the configured
     * capability without altering the request payload.
     */
    public function supportsStreaming(): bool
    {
        return $this->openRouterConfig->supportsStreaming;
    }

    public function supportsFunctionCalling(): bool
    {
        return $this->openRouterConfig->supportsFunctionCalling;
    }

    /**
     * Probes connectivity and authentication with the credential endpoint, then
     * verifies the configured default model against the catalogue. Both calls
     * are read-only — no generation is billed.
     *
     * A reachable, authenticated account whose configured model cannot be
     * verified is reported as degraded rather than unavailable: the credential
     * works, but one route through it is in doubt.
     */
    public function healthCheck(): ProviderHealthDTO
    {
        $checkedAt = now()->toIso8601String();

        try {
            $model = $this->models->resolve(null);
            $startedAt = hrtime(true);
            $key = $this->client->key();
            $latencyMs = $this->elapsedMilliseconds($startedAt);
            $verified = $this->verifyModel($model);

            return new ProviderHealthDTO(
                key: self::KEY,
                status: $verified === false ? HealthStatus::Degraded : HealthStatus::Healthy,
                latencyMs: $latencyMs,
                message: $verified === false
                    ? "Configured model [{$model}] could not be verified against the OpenRouter catalogue."
                    : null,
                checkedAt: $checkedAt,
                details: [
                    'default_model' => $model,
                    'model_verified' => $verified,
                    'models_configured' => count($this->models->all()),
                    'upstream_providers' => $this->models->upstreamProviders(),
                    'usage_accounting' => $this->openRouterConfig->usageAccounting,
                    ...$this->credential($key),
                ],
            );
        } catch (Throwable $exception) {
            return ProviderHealthDTO::unavailable(self::KEY, $exception->getMessage(), $checkedAt);
        }
    }

    /**
     * Whether the catalogue confirms the model, or null when the identifier is
     * not namespaced and therefore not addressable on the catalogue route.
     */
    private function verifyModel(string $model): ?bool
    {
        $route = $this->models->catalogueRoute($model);

        if ($route === null) {
            return null;
        }

        try {
            return isset($this->client->modelEndpoints($route['author'], $route['slug'])['data']);
        } catch (AIException) {
            return false;
        }
    }

    /**
     * The credential facts worth surfacing on a health report. Absent fields are
     * omitted rather than defaulted, so the report never overstates what the
     * vendor returned.
     *
     * @param  array<string, mixed>  $key
     * @return array<string, mixed>
     */
    private function credential(array $key): array
    {
        $data = $key['data'] ?? null;

        if (! is_array($data)) {
            return [];
        }

        return array_filter([
            'key_label' => is_string($data['label'] ?? null) ? $data['label'] : null,
            'credit_limit' => is_numeric($data['limit'] ?? null) ? (float) $data['limit'] : null,
            'credits_used' => is_numeric($data['usage'] ?? null) ? (float) $data['usage'] : null,
            'free_tier' => is_bool($data['is_free_tier'] ?? null) ? $data['is_free_tier'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function textPayload(TextRequest $request, string $model): array
    {
        $payload = array_filter([
            'model' => $model,
            'messages' => $this->messages($request),
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'top_p' => $request->topP,
            'stream' => $request->stream,
            'tools' => $request->tools !== [] ? $request->tools : null,
            'stop' => $request->stop !== [] ? $request->stop : null,
            // Opts into the vendor's settled-cost reporting for accurate spend.
            'usage' => $this->openRouterConfig->usageAccounting ? ['include' => true] : null,
        ], static fn (mixed $value): bool => $value !== null);

        return [...$payload, ...$request->options];
    }

    /**
     * The chat transcript. An explicit system instruction is hoisted in front of
     * the conversation; a system turn already inside `messages` is left where
     * the caller put it.
     *
     * @return list<array{role: string, content: string}>
     */
    private function messages(TextRequest $request): array
    {
        $messages = $request->messages !== []
            ? $request->messages
            : [['role' => 'user', 'content' => $request->prompt]];

        if ($request->system === null || $request->system === '') {
            return $messages;
        }

        return [['role' => 'system', 'content' => $request->system], ...$messages];
    }
}
