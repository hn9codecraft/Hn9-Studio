<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\Contracts\ProviderFactoryInterface;
use App\AI\Contracts\ProviderManagerInterface;
use App\AI\Contracts\ProviderRegistryInterface;
use App\AI\DTOs\ProviderConfigDTO;
use App\AI\DTOs\ProviderRequestDTO;
use App\AI\Exceptions\ProviderApiException;
use App\AI\Exceptions\ProviderAuthenticationException;
use App\AI\Exceptions\ProviderNetworkException;
use App\AI\Exceptions\ProviderNotConfiguredException;
use App\AI\Exceptions\ProviderRateLimitException;
use App\AI\Exceptions\ProviderTimeoutException;
use App\AI\Exceptions\UnsupportedCapabilityException;
use App\AI\Providers\OpenRouter\OpenRouterClient;
use App\AI\Providers\OpenRouter\OpenRouterConfig;
use App\AI\Providers\OpenRouter\OpenRouterModelRegistry;
use App\AI\Providers\OpenRouter\OpenRouterProvider;
use App\AI\Providers\OpenRouter\OpenRouterResponseNormalizer;
use App\AI\Providers\OpenRouter\OpenRouterTokenCounter;
use App\AI\Providers\OpenRouter\OpenRouterUsageCalculator;
use App\AI\Requests\ImageRequest;
use App\AI\Requests\TextRequest;
use App\AI\Requests\VideoRequest;
use App\AI\Requests\VoiceRequest;
use App\AI\Support\Capability;
use App\AI\Support\HealthStatus;
use App\AI\Support\Modality;
use App\AI\Support\ProviderConfigResolver;
use App\Providers\AIServiceProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenRouterProviderTest extends TestCase
{
    private const CHAT_URL = 'https://openrouter.ai/api/v1/chat/completions';

    private const KEY_URL = 'https://openrouter.ai/api/v1/key';

    private const ENDPOINTS_URL = 'https://openrouter.ai/api/v1/models/vendor-a/model-one/endpoints';

    /**
     * Builds the adapter exactly as the service provider does, from a
     * configuration array — nothing about the vendor or its catalogue is
     * hardcoded in the adapter.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function provider(array $overrides = []): OpenRouterProvider
    {
        $config = $this->config($overrides);
        $usage = new OpenRouterUsageCalculator($config);

        return new OpenRouterProvider(
            new OpenRouterClient($this->app->make(Factory::class), $config),
            new OpenRouterModelRegistry($config),
            $usage,
            new OpenRouterResponseNormalizer($usage),
            new OpenRouterTokenCounter,
            $config,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function config(array $overrides = []): OpenRouterConfig
    {
        return OpenRouterConfig::fromProviderConfig(
            ProviderConfigDTO::fromArray('openrouter', [...$this->settings(), ...$overrides]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
            'api_key' => 'test-key',
            'base_url' => 'https://openrouter.ai/api/v1',
            'default_model' => 'vendor-a/model-one',
            'models' => ['vendor-a/model-one', 'vendor-b/model-two'],
            'http_referer' => 'https://studio.test',
            'app_name' => 'HN9 AI Studio',
            'headers' => ['X-Studio-Tenant' => 'acme'],
            'usage_accounting' => true,
            'supports_streaming' => true,
            'supports_function_calling' => true,
            'model_metadata' => [
                'vendor-a/model-one' => [
                    'capabilities' => ['text'],
                    'context_window' => 128000,
                    'max_output_tokens' => 16384,
                ],
                'vendor-b/model-two' => [
                    'provider' => 'reseller-b',
                    'streaming' => false,
                    'function_calling' => false,
                ],
            ],
            'pricing' => ['vendor-a/model-one' => ['input' => 3.0, 'output' => 15.0]],
        ];
    }

    /**
     * A chat-completions payload without vendor-reported cost.
     *
     * @return array<string, mixed>
     */
    private function completion(): array
    {
        return [
            'id' => 'gen-123',
            'model' => 'vendor-a/model-one',
            'provider' => 'Vendor A',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hello from the router.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
        ];
    }

    // ---------------------------------------------------------------- text --

    public function test_text_generation_is_normalized_into_the_shared_text_response(): void
    {
        Http::fake([self::CHAT_URL => Http::response($this->completion())]);

        $response = $this->provider()->generateText(new TextRequest(prompt: 'Hello'));

        $this->assertSame('Hello from the router.', $response->text);
        $this->assertSame('vendor-a/model-one', $response->model);
        $this->assertSame('stop', $response->finishReason);
        $this->assertSame(10, $response->usage?->promptTokens);
        $this->assertSame(20, $response->usage?->completionTokens);
        $this->assertSame(30, $response->usage?->totalTokens);
        $this->assertNotNull($response->usage?->executionTimeMs);
        // 10 prompt tokens at 3.00/M + 20 completion tokens at 15.00/M.
        $this->assertEqualsWithDelta(0.00033, $response->usage?->cost ?? 0.0, 0.0000001);
        // The router payload is retained for telemetry without widening the contract.
        $this->assertSame('Vendor A', $response->raw['provider']);
    }

    public function test_text_payload_maps_messages_generation_settings_and_usage_accounting(): void
    {
        Http::fake([self::CHAT_URL => Http::response($this->completion())]);

        $this->provider()->generateText(new TextRequest(
            prompt: 'ignored when messages are supplied',
            model: 'vendor-b/model-two',
            messages: [
                ['role' => 'user', 'content' => 'Ping'],
                ['role' => 'assistant', 'content' => 'Pong'],
            ],
            maxTokens: 256,
            temperature: 0.4,
            topP: 0.9,
            tools: [['type' => 'function', 'function' => ['name' => 'lookup']]],
            system: 'Studio voice.',
            stop: ['END'],
        ));

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === self::CHAT_URL
                && $body['model'] === 'vendor-b/model-two'
                && $body['messages'] === [
                    ['role' => 'system', 'content' => 'Studio voice.'],
                    ['role' => 'user', 'content' => 'Ping'],
                    ['role' => 'assistant', 'content' => 'Pong'],
                ]
                && $body['max_tokens'] === 256
                && $body['temperature'] === 0.4
                && $body['top_p'] === 0.9
                && $body['stop'] === ['END']
                && $body['tools'] === [['type' => 'function', 'function' => ['name' => 'lookup']]]
                && $body['usage'] === ['include' => true];
        });
    }

    public function test_a_bare_prompt_becomes_a_single_user_turn_and_usage_accounting_is_configurable(): void
    {
        Http::fake([self::CHAT_URL => Http::response($this->completion())]);

        $this->provider(['usage_accounting' => false])->generateText(new TextRequest(prompt: 'Hello'));

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $body['messages'] === [['role' => 'user', 'content' => 'Hello']]
                && ! array_key_exists('usage', $body);
        });
    }

    public function test_router_specific_options_are_passed_through_untouched(): void
    {
        Http::fake([self::CHAT_URL => Http::response($this->completion())]);

        $this->provider()->generateText(new TextRequest(
            prompt: 'Hello',
            options: ['provider' => ['order' => ['vendor-a']], 'route' => 'fallback'],
        ));

        Http::assertSent(fn (Request $request): bool => $request->data()['provider'] === ['order' => ['vendor-a']]
            && $request->data()['route'] === 'fallback');
    }

    public function test_configured_headers_and_attribution_accompany_every_request(): void
    {
        Http::fake([self::CHAT_URL => Http::response($this->completion())]);

        $this->provider()->generateText(new TextRequest(prompt: 'Hello'));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key')
            && $request->hasHeader('HTTP-Referer', 'https://studio.test')
            && $request->hasHeader('X-Title', 'HN9 AI Studio')
            && $request->hasHeader('X-Studio-Tenant', 'acme'));
    }

    public function test_the_credential_header_cannot_be_displaced_by_configured_headers(): void
    {
        Http::fake([self::CHAT_URL => Http::response($this->completion())]);

        $this->provider(['headers' => ['Authorization' => 'Bearer spoofed']])
            ->generateText(new TextRequest(prompt: 'Hello'));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    // --------------------------------------------------------- normalization --

    public function test_content_parts_are_concatenated_and_the_served_model_is_reported(): void
    {
        Http::fake([self::CHAT_URL => Http::response([
            'model' => 'vendor-a/model-one:nitro',
            'choices' => [[
                'message' => ['content' => [['type' => 'text', 'text' => 'Part one. '], ['type' => 'text', 'text' => 'Part two.']]],
                'native_finish_reason' => 'end_turn',
            ]],
        ])]);

        $response = $this->provider()->generateText(new TextRequest(prompt: 'x'));

        $this->assertSame('Part one. Part two.', $response->text);
        $this->assertSame('vendor-a/model-one:nitro', $response->model);
        $this->assertSame('end_turn', $response->finishReason);
        $this->assertNull($response->usage);
    }

    public function test_the_vendor_reported_cost_overrides_the_configured_rates(): void
    {
        Http::fake([self::CHAT_URL => Http::response([
            ...$this->completion(),
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30, 'cost' => 0.0025],
        ])]);

        $response = $this->provider()->generateText(new TextRequest(prompt: 'Hello'));

        // The router settles the charge; the configured rate would have said 0.00033.
        $this->assertEqualsWithDelta(0.0025, $response->usage?->cost ?? 0.0, 0.0000001);
        $this->assertSame(30, $response->usage?->totalTokens);
    }

    public function test_responses_are_wrapped_in_the_provider_independent_envelope(): void
    {
        Http::fake([self::CHAT_URL => Http::response($this->completion())]);

        $config = $this->config();
        $normalizer = new OpenRouterResponseNormalizer(new OpenRouterUsageCalculator($config));
        $envelope = $normalizer->envelope($this->provider()->generateText(new TextRequest(prompt: 'Hello')));

        $this->assertTrue($envelope->success);
        $this->assertSame(Modality::Text, $envelope->modality);
        $this->assertSame('openrouter', $envelope->providerKey);
        $this->assertSame('vendor-a/model-one', $envelope->model);
        $this->assertSame('Hello from the router.', $envelope->payload['text']);
        $this->assertSame(30, $envelope->usage?->totalTokens);
    }

    public function test_the_normalizer_exposes_the_vendors_authoritative_token_count(): void
    {
        $config = $this->config();
        $normalizer = new OpenRouterResponseNormalizer(new OpenRouterUsageCalculator($config));

        $tokens = $normalizer->tokens($this->completion(), 'vendor-a/model-one');

        $this->assertSame(30, $tokens->count);
        $this->assertSame('vendor-a/model-one', $tokens->model);
    }

    public function test_a_response_without_a_token_count_is_a_typed_parsing_failure(): void
    {
        $config = $this->config();
        $normalizer = new OpenRouterResponseNormalizer(new OpenRouterUsageCalculator($config));

        $this->expectException(ProviderApiException::class);
        $normalizer->tokens(['choices' => []], 'vendor-a/model-one');
    }

    // ---------------------------------------------------- models & metadata --

    public function test_models_are_loaded_dynamically_from_configuration(): void
    {
        $provider = $this->provider();

        $this->assertSame(['vendor-a/model-one', 'vendor-b/model-two'], $provider->supportedModels());
        $this->assertSame('openrouter', $provider->providerName());
        $this->assertSame(OpenRouterProvider::VERSION, $provider->providerVersion());
        $this->assertTrue($provider->supportsStreaming());
        $this->assertTrue($provider->supportsFunctionCalling());
        $this->assertFalse($this->provider(['supports_streaming' => false])->supportsStreaming());
    }

    public function test_any_future_namespaced_model_is_adopted_by_configuration_alone(): void
    {
        Http::fake([self::CHAT_URL => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        $future = ['deepseek/deepseek-v9', 'qwen/qwen-9-max', 'mistralai/mistral-next', 'meta-llama/llama-9-instruct'];
        $provider = $this->provider(['models' => $future, 'default_model' => 'qwen/qwen-9-max', 'model_metadata' => []]);

        $this->assertSame($future, $provider->supportedModels());
        $this->assertSame('ok', $provider->generateText(new TextRequest(prompt: 'x', model: 'mistralai/mistral-next'))->text);
        Http::assertSent(fn (Request $request): bool => $request->data()['model'] === 'mistralai/mistral-next');
    }

    public function test_an_unconfigured_model_is_rejected_before_any_call_is_made(): void
    {
        Http::fake();

        try {
            $this->provider()->generateText(new TextRequest(prompt: 'x', model: 'vendor-c/unlisted'));
            $this->fail('Expected an unconfigured-model failure.');
        } catch (ProviderNotConfiguredException $exception) {
            $this->assertSame('openrouter', $exception->context()['key'] ?? null);
        }

        Http::assertNothingSent();
    }

    public function test_metadata_is_provided_for_every_configured_model(): void
    {
        $metadata = $this->provider()->modelMetadata();

        $this->assertSame(['vendor-a/model-one', 'vendor-b/model-two'], array_keys($metadata));

        $first = $metadata['vendor-a/model-one'];
        $this->assertSame('vendor-a', $first->provider);
        $this->assertTrue($first->supports(Capability::Text));
        $this->assertTrue($first->supports(Capability::Streaming));
        $this->assertTrue($first->supports(Capability::FunctionCalling));
        $this->assertSame(128000, $first->contextWindow);
        $this->assertSame(16384, $first->maxOutputTokens);
        $this->assertSame(['input' => 3.0, 'output' => 15.0], $first->pricing);
        $this->assertTrue($first->isPriced());

        // Model level declarations win over the provider level defaults.
        $second = $metadata['vendor-b/model-two'];
        $this->assertSame('reseller-b', $second->provider);
        $this->assertFalse($second->supports(Capability::Streaming));
        $this->assertFalse($second->supports(Capability::FunctionCalling));
        $this->assertNull($second->contextWindow);
        $this->assertFalse($second->isPriced());
    }

    public function test_metadata_defaults_to_the_identifier_namespace_when_undeclared(): void
    {
        $metadata = $this->provider(['model_metadata' => [], 'pricing' => []])
            ->modelMetadataFor('vendor-b/model-two');

        $this->assertSame('vendor-b', $metadata->provider);
        $this->assertSame([Capability::Text], $metadata->capabilities);
        $this->assertNull($metadata->contextWindow);
        $this->assertSame([
            'id' => 'vendor-b/model-two',
            'provider' => 'vendor-b',
            'capabilities' => ['text'],
            'streaming' => true,
            'function_calling' => true,
            'context_window' => null,
            'max_output_tokens' => null,
            'pricing' => null,
        ], $metadata->toArray());
    }

    public function test_metadata_for_an_unconfigured_model_is_rejected(): void
    {
        $this->expectException(ProviderNotConfiguredException::class);
        $this->provider()->modelMetadataFor('vendor-c/unlisted');
    }

    // ------------------------------------------------------------- counting --

    public function test_token_counting_uses_the_shared_local_estimate(): void
    {
        Http::fake();

        $tokens = $this->provider()->countTokens('12345678');

        $this->assertSame(2, $tokens->count);
        $this->assertSame('vendor-a/model-one', $tokens->model);
        Http::assertNothingSent();
    }

    public function test_cost_estimation_prices_tokens_with_the_configured_rates(): void
    {
        $cost = $this->provider()->estimateCost(
            ProviderRequestDTO::fromRequest(new TextRequest(prompt: '12345678', maxTokens: 10)),
        );

        // 2 prompt tokens at 3.00/M + 10 output tokens at 15.00/M.
        $this->assertEqualsWithDelta(0.000156, $cost, 0.0000001);
    }

    public function test_cost_estimation_of_an_unpriced_model_is_zero_rather_than_invented(): void
    {
        $cost = $this->provider()->estimateCost(
            ProviderRequestDTO::fromRequest(new TextRequest(prompt: '12345678', model: 'vendor-b/model-two', maxTokens: 10)),
        );

        $this->assertSame(0.0, $cost);
    }

    // --------------------------------------------------------------- health --

    public function test_health_check_validates_authentication_and_the_configured_model(): void
    {
        Http::fake([
            self::KEY_URL => Http::response(['data' => [
                'label' => 'studio-key', 'usage' => 1.5, 'limit' => 20.0, 'is_free_tier' => false,
            ]]),
            self::ENDPOINTS_URL => Http::response(['data' => ['id' => 'vendor-a/model-one', 'endpoints' => []]]),
        ]);

        $health = $this->provider()->healthCheck();

        $this->assertSame(HealthStatus::Healthy, $health->status);
        $this->assertTrue($health->isOperational());
        $this->assertSame('openrouter', $health->key);
        $this->assertNotNull($health->latencyMs);
        $this->assertNotNull($health->checkedAt);
        $this->assertSame('vendor-a/model-one', $health->details['default_model']);
        $this->assertTrue($health->details['model_verified']);
        $this->assertSame(2, $health->details['models_configured']);
        $this->assertSame(['vendor-a', 'reseller-b'], $health->details['upstream_providers']);
        $this->assertSame('studio-key', $health->details['key_label']);
        $this->assertSame(20.0, $health->details['credit_limit']);
        $this->assertSame(1.5, $health->details['credits_used']);
        $this->assertFalse($health->details['free_tier']);
    }

    public function test_health_check_is_degraded_when_the_configured_model_is_unroutable(): void
    {
        Http::fake([
            self::KEY_URL => Http::response(['data' => ['label' => 'studio-key']]),
            self::ENDPOINTS_URL => Http::response(['error' => ['code' => 404, 'message' => 'No endpoints found']], 404),
        ]);

        $health = $this->provider()->healthCheck();

        $this->assertSame(HealthStatus::Degraded, $health->status);
        // Degraded still routes: the credential works, one model is in doubt.
        $this->assertTrue($health->isOperational());
        $this->assertFalse($health->details['model_verified']);
        $this->assertStringContainsString('vendor-a/model-one', (string) $health->message);
    }

    public function test_health_check_reports_unavailable_when_authentication_fails(): void
    {
        Http::fake([self::KEY_URL => Http::response(['error' => ['code' => 401, 'message' => 'No auth credentials found']], 401)]);

        $health = $this->provider()->healthCheck();

        $this->assertSame(HealthStatus::Unavailable, $health->status);
        $this->assertFalse($health->isOperational());
    }

    public function test_health_check_reports_unavailable_when_no_model_is_configured(): void
    {
        Http::fake();

        $health = $this->provider(['default_model' => null, 'models' => []])->healthCheck();

        $this->assertFalse($health->isOperational());
        $this->assertStringContainsString('openrouter', (string) $health->message);
    }

    // ------------------------------------------------------------ contracts --

    public function test_image_video_and_voice_report_the_unsupported_capability(): void
    {
        $provider = $this->provider();

        foreach ([Capability::Image, Capability::Video, Capability::Voice] as $capability) {
            try {
                match ($capability) {
                    Capability::Image => $provider->generateImage(new ImageRequest(prompt: 'x')),
                    Capability::Video => $provider->generateVideo(new VideoRequest(prompt: 'x')),
                    default => $provider->generateVoice(new VoiceRequest(input: 'x')),
                };
                $this->fail("Expected an unsupported-capability exception for {$capability->value}.");
            } catch (UnsupportedCapabilityException $exception) {
                $this->assertSame($capability->value, $exception->context()['capability'] ?? null);
                $this->assertSame('openrouter', $exception->context()['provider'] ?? null);
            }
        }
    }

    // ----------------------------------------------------- error taxonomies --

    public function test_http_401_is_mapped_to_a_typed_authentication_exception(): void
    {
        Http::fake([self::CHAT_URL => Http::response(['error' => ['code' => 401, 'message' => 'No auth credentials found']], 401)]);

        $this->expectException(ProviderAuthenticationException::class);
        $this->provider()->generateText(new TextRequest(prompt: 'x'));
    }

    public function test_http_403_is_a_moderation_failure_not_an_authentication_failure(): void
    {
        // OpenRouter reserves 403 for moderation, so it must not read as a bad key.
        Http::fake([self::CHAT_URL => Http::response([
            'error' => ['code' => 403, 'message' => 'Input flagged by moderation', 'metadata' => ['reasons' => ['violence']]],
        ], 403)]);

        try {
            $this->provider()->generateText(new TextRequest(prompt: 'x'));
            $this->fail('Expected a provider API exception.');
        } catch (ProviderApiException $exception) {
            $this->assertSame('Input flagged by moderation', $exception->getMessage());
            $this->assertSame(403, $exception->statusCode());
        }
    }

    public function test_insufficient_credits_are_mapped_to_a_typed_api_exception(): void
    {
        Http::fake([self::CHAT_URL => Http::response(['error' => ['code' => 402, 'message' => 'Insufficient credits']], 402)]);

        try {
            $this->provider()->generateText(new TextRequest(prompt: 'x'));
            $this->fail('Expected a provider API exception.');
        } catch (ProviderApiException $exception) {
            $this->assertSame('Insufficient credits', $exception->getMessage());
            $this->assertSame(402, $exception->statusCode());
        }
    }

    public function test_rate_limiting_is_mapped_to_a_typed_exception(): void
    {
        Http::fake([self::CHAT_URL => Http::response(['error' => ['code' => 429, 'message' => 'Rate limit exceeded']], 429)]);

        $this->expectException(ProviderRateLimitException::class);
        $this->provider()->generateText(new TextRequest(prompt: 'x'));
    }

    public function test_an_invalid_model_rejected_by_the_router_is_a_typed_api_exception(): void
    {
        Http::fake([self::CHAT_URL => Http::response([
            'error' => ['code' => 400, 'message' => 'vendor-b/model-two is not a valid model ID'],
        ], 400)]);

        try {
            $this->provider()->generateText(new TextRequest(prompt: 'x', model: 'vendor-b/model-two'));
            $this->fail('Expected a provider API exception.');
        } catch (ProviderApiException $exception) {
            $this->assertSame('vendor-b/model-two is not a valid model ID', $exception->getMessage());
            $this->assertSame('openrouter', $exception->context()['provider'] ?? null);
        }
    }

    public function test_an_error_embedded_in_a_successful_envelope_is_still_typed(): void
    {
        // The transport succeeded but the routed upstream call did not.
        Http::fake([self::CHAT_URL => Http::response(['error' => ['code' => 502, 'message' => 'Upstream provider error']], 200)]);

        try {
            $this->provider()->generateText(new TextRequest(prompt: 'x'));
            $this->fail('Expected a provider API exception.');
        } catch (ProviderApiException $exception) {
            $this->assertSame('Upstream provider error', $exception->getMessage());
            $this->assertSame(502, $exception->statusCode());
        }
    }

    public function test_an_embedded_rate_limit_error_keeps_its_typed_meaning(): void
    {
        Http::fake([self::CHAT_URL => Http::response(['error' => ['code' => 429, 'message' => 'Rate limit exceeded']], 200)]);

        $this->expectException(ProviderRateLimitException::class);
        $this->provider()->generateText(new TextRequest(prompt: 'x'));
    }

    public function test_a_timeout_is_mapped_to_a_typed_timeout_exception(): void
    {
        Http::fake(fn (): never => throw new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds'));

        $this->expectException(ProviderTimeoutException::class);
        $this->provider()->generateText(new TextRequest(prompt: 'x'));
    }

    public function test_a_connection_failure_is_mapped_to_a_typed_network_exception(): void
    {
        Http::fake(fn (): never => throw new ConnectionException('Could not resolve host: openrouter.ai'));

        $this->expectException(ProviderNetworkException::class);
        $this->provider()->generateText(new TextRequest(prompt: 'x'));
    }

    public function test_an_unparseable_response_is_mapped_to_a_typed_exception(): void
    {
        Http::fake([self::CHAT_URL => Http::response(['choices' => [['message' => ['tool_calls' => []]]]])]);

        $this->expectException(ProviderApiException::class);
        $this->provider()->generateText(new TextRequest(prompt: 'x'));
    }

    // -------------------------------------------------------- configuration --

    public function test_missing_credentials_raise_a_configuration_error(): void
    {
        $this->expectException(ProviderNotConfiguredException::class);
        $this->config(['api_key' => null]);
    }

    public function test_a_missing_base_url_raises_a_configuration_error(): void
    {
        $this->expectException(ProviderNotConfiguredException::class);
        $this->config(['base_url' => '']);
    }

    public function test_configuration_is_loaded_dynamically_from_config_and_the_environment(): void
    {
        config()->set('ai.providers.openrouter', [
            'api_key' => 'env-key',
            'base_url' => 'https://openrouter.ai/api/v1/',
            'default_model' => 'vendor-x/model-future',
            'models' => ['vendor-x/model-future', ' vendor-y/model-next ', ''],
            'timeout' => 45,
            'max_retries' => 5,
            'http_referer' => 'https://studio.example',
            'app_name' => 'Studio',
            'headers' => ['X-Trace' => 'on', '' => 'dropped'],
        ]);

        $config = OpenRouterConfig::fromProviderConfig(
            $this->app->make(ProviderConfigResolver::class)->resolve('openrouter'),
        );

        $this->assertSame('env-key', $config->apiKey);
        $this->assertSame('https://openrouter.ai/api/v1', $config->baseUrl);
        $this->assertSame(['vendor-x/model-future', 'vendor-y/model-next'], $config->models);
        $this->assertSame('vendor-x/model-future', $config->defaultModel);
        $this->assertSame(45, $config->timeout);
        $this->assertSame(5, $config->maxRetries);
        $this->assertSame([
            'HTTP-Referer' => 'https://studio.example',
            'X-Title' => 'Studio',
            'X-Trace' => 'on',
        ], $config->requestHeaders());
    }

    public function test_attribution_headers_are_omitted_when_not_configured(): void
    {
        $config = $this->config(['http_referer' => null, 'app_name' => null, 'headers' => []]);

        $this->assertSame([], $config->requestHeaders());
    }

    // --------------------------------------------------------- registration --

    public function test_openrouter_registers_through_the_provider_registry(): void
    {
        config()->set('ai.providers.openrouter', [...$this->settings(), 'enabled' => true, 'priority' => 70]);

        (new AIServiceProvider($this->app))->boot();

        $registry = $this->app->make(ProviderRegistryInterface::class);
        $manager = $this->app->make(ProviderManagerInterface::class);

        $this->assertTrue($registry->has('openrouter'));
        $this->assertSame('openrouter', $this->app->make(ProviderFactoryInterface::class)->make('openrouter')->providerName());
        $this->assertContains('openrouter', $manager->forCapability(Capability::Text));
        $this->assertNotContains('openrouter', $manager->forCapability(Capability::Image));

        $capabilities = $manager->capabilities('openrouter');
        $this->assertSame(['vendor-a/model-one', 'vendor-b/model-two'], $capabilities->models);
        $this->assertSame(OpenRouterProvider::VERSION, $capabilities->version);
        $this->assertSame(128000, $capabilities->maxInputTokens);
    }

    public function test_a_disabled_provider_is_never_registered(): void
    {
        config()->set('ai.providers.openrouter', [...$this->settings(), 'enabled' => false]);

        (new AIServiceProvider($this->app))->boot();

        $this->assertFalse($this->app->make(ProviderRegistryInterface::class)->has('openrouter'));
    }

    public function test_openrouter_registers_alongside_the_existing_providers_without_disturbing_them(): void
    {
        config()->set('ai.providers.openrouter', [...$this->settings(), 'enabled' => true]);
        config()->set('ai.providers.openai', [
            'enabled' => true, 'api_key' => 'test-key', 'base_url' => 'https://api.openai.com/v1',
            'default_model' => 'future-model', 'models' => ['future-model'],
        ]);
        config()->set('ai.providers.claude', [
            'enabled' => true, 'api_key' => 'test-key', 'base_url' => 'https://api.example/v1',
            'version' => 'test-version', 'default_model' => 'future-claude', 'models' => ['future-claude'],
        ]);
        config()->set('ai.providers.gemini', [
            'enabled' => true, 'api_key' => 'test-key', 'base_url' => 'https://generativelanguage.googleapis.com',
            'version' => 'v1beta', 'default_model' => 'future-gemini', 'models' => ['future-gemini'],
        ]);

        (new AIServiceProvider($this->app))->boot();

        $manager = $this->app->make(ProviderManagerInterface::class);
        $factory = $this->app->make(ProviderFactoryInterface::class);

        foreach (['openrouter', 'openai', 'claude', 'gemini'] as $key) {
            $this->assertTrue($manager->has($key));
            $this->assertSame($key, $factory->make($key)->providerName());
        }

        // Priority ordering stays configuration-driven: OpenAI 100, Claude 90, Gemini 80, OpenRouter 70.
        $this->assertSame(['openai', 'claude', 'gemini', 'openrouter'], $manager->forCapability(Capability::Text));
    }

    public function test_aggregate_health_isolates_openrouter_from_the_other_providers(): void
    {
        config()->set('ai.providers.openrouter', [...$this->settings(), 'enabled' => true]);
        config()->set('ai.providers.openai', [
            'enabled' => true, 'api_key' => 'test-key', 'base_url' => 'https://api.openai.com/v1',
            'default_model' => 'future-model', 'models' => ['future-model'],
        ]);

        Http::fake([
            self::KEY_URL => Http::response(['data' => ['label' => 'studio-key']]),
            self::ENDPOINTS_URL => Http::response(['data' => ['id' => 'vendor-a/model-one']]),
            'https://api.openai.com/*' => Http::response([], 500),
        ]);

        (new AIServiceProvider($this->app))->boot();

        $health = $this->app->make(ProviderManagerInterface::class)->health();

        $this->assertTrue($health['openrouter']->isOperational());
        $this->assertFalse($health['openai']->isOperational());
    }
}
